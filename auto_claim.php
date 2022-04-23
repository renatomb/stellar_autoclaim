<?php

/*
This script claims any claimable balance of a trusted asset.
Assets without trustline will be ignored.

In this model we write a python script for each wallet listed in the database.
If there is no private key, unsigned XDR will be print on the screen.
*/

$contas=json_decode(file_get_contents("wallets_and_keys.json"),true);

foreach($contas as $pub => $pvt) {
   verificarConta($pub,$pvt);
}

function verificarConta($myAddr,$pvt) {
   echo "Checking wallet $myAddr\n";
   $claimable = get_page("https://horizon.stellar.org/claimable_balances/?limit=200&claimant=$myAddr");
   $claimable=$claimable["_embedded"]["records"];
   $wlt="[" . substr($myAddr,-10) . "]";
   if (count($claimable) > 0) {
      $trustlines=array();
      $ids_claims=array();
      $account=get_page("https://horizon.stellar.org/accounts/$myAddr");
      $balances=$account["balances"];
      for ($i=0;$i<count($claimable);$i++) {
         $asset=explode(":",$claimable[$i]["asset"]);
         echo "\n$wlt (" . $asset[0] . ") ";
         //Verificar se possui trustline pro asset
         $possui_trustline=false;
         $claimants=$claimable[$i]["claimants"];
         $pode_claim=check_claimants($claimants,$myAddr);
         for ($j=0;$j<count($balances);$j++){
            if (($balances[$j]["asset_code"] == $asset[0]) && ($balances[$j]["asset_issuer"] == $asset[1])){
               echo "☑️"; // Já possui trustline
               if ($pode_claim) {
                  echo $claimable[$i]["id"];
                  $ids_claims[]=$claimable[$i]["id"];
               }
               $possui_trustline=true;
            }
         }
/*         if (!$possui_trustline && $pode_claim) {
            if (strlen($asset[0]) <= 4) {
               $ass_type="credit_alphanum4";
            }
            else {
               $ass_type="credit_alphanum12";
            }
            $cotacao=get_page("https://horizon.stellar.org/order_book?selling_asset_type=$ass_type&selling_asset_code=" . $asset[0] . "&selling_asset_issuer=" . $asset[1] . "&buying_asset_type=native");
            $cotacao=$cotacao["bids"];
            if (count($cotacao) > 0) {
               $preco = $cotacao[0]["price"];
               $valor_mercado=$claimable[$i]["amount"]*$preco;
               echo "Preco: $preco XLM x " . $claimable[$i]["amount"] . " = $valor_mercado XLM\n";
               echo $claimable[$i]["asset"] . " - " . $claimable[$i]["id"];
               $id_asset=str_replace(":","_",$claimable[$i]["asset"]);
               $trustlines[$id_asset]=$claimable[$i]["asset"];
               $ids_claims[]=$claimable[$i]["id"];
            }
            else {
               echo $asset[0] . " sem ofertas.";
            }
         }*/
      }
   }
   else {
      echo "0️⃣ no claimable.";
   }
   echo "\n";
   if (count($ids_claims) > 0) {
      proceed_trans($myAddr,array(),$ids_claims,$pvt);
   }
}

function check_claimants($claimants,$myAddr){
   $pode_claim=false;
   $now = new DateTime();
   $agora=time();
   for ($k=0;$k<count($claimants);$k++) {
      if ($claimants[$k]["destination"] == $myAddr) {
         if ($claimants[$k]["predicate"]["unconditional"] == 1) {
            echo "‼️ unconditional.";
            $pode_claim=true;
         }
         elseif (isset($claimants[$k]["predicate"]["and"])) {
            $ate_quando=$claimants[$k]["predicate"]["and"][1]["abs_before_epoch"];
            $apartir_quando=$claimants[$k]["predicate"]["and"][0]["not"]["abs_before_epoch"];
            if ($apartir_quando > $agora) {
               $pode_claim=false;
               echo "⏳ wait " . secondsToTime($apartir_quando);
            }
            elseif($ate_quando < $agora) {
               $pode_claim=false;
               echo "💸 expired";
            }
            else {
               echo "✅ ($apartir_quando < $agora < $ate_quando) available ";
               $pode_claim=true;
            }
         }
         elseif (isset($claimants[$k]["predicate"]["abs_before_epoch"])) {
            $ate_quando=$claimants[$k]["predicate"]["abs_before_epoch"];
            if($ate_quando < $agora) {
               $pode_claim=false;
               echo "❌ expired";
            }
            else {
               echo "✅ available.";
               $pode_claim=true;
            }
         }
         elseif (isset($claimants[$k]["predicate"]["not"]["abs_before_epoch"])) {
            $apartir_quando=$claimants[$k]["predicate"]["not"]["abs_before_epoch"];
            if ($apartir_quando > $agora) {
               $pode_claim=false;
               echo "⏳ wait " . secondsToTime($apartir_quando);
            }
            else {
               echo "✅ available";
               $pode_claim=true;
            }
         }
      }
   }
   return $pode_claim;
}

function get_page($url) {
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, True);
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl,CURLOPT_USERAGENT,'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.7; rv:7.0.1) Gecko/20100101 Firefox/7.0.1');
    $return = curl_exec($curl);
    curl_close($curl);
    return json_decode($return,true);
}

function proceed_trans($account,$trusts,$claims,$myPrivateKey=null) {
   //Path for the temporary pythin generated file below:
   $caminho="/Users/renato/Downloads/exec_" . uniqid() . ".py";

   $gravar=fopen($caminho,"w+");
   fwrite($gravar,'from stellar_sdk import Server, Keypair, TransactionBuilder, Network, FeeBumpTransaction, ClaimPredicate, Claimant, Asset
import requests

# 1. Load Keys
server = Server("https://horizon.stellar.org")
print("Gerando XDR...")

base_fee = 111
account = server.load_account("' . $account . '")

transaction = TransactionBuilder(
   source_account=account,
   network_passphrase=Network.PUBLIC_NETWORK_PASSPHRASE,
   base_fee=base_fee
)');
//   for($i=0;$i<count($trusts);$i++) {
   foreach ($trusts as $key => $tline) {
      $asset=explode(":",$tline);
      fwrite($gravar,'.append_change_trust_op(
   asset_code="' . $asset[0] . '", asset_issuer="' . $asset[1] . '"
)');
   }
   for ($i=0;$i<count($claims);$i++) {
      fwrite($gravar,'.append_claim_claimable_balance_op(
   balance_id="' . $claims[$i] . '",
   source="' . $account . '"
)');
   }
   fwrite($gravar,'
tx = transaction.build()');
   if ((substr($myPrivateKey,0,1) == "S") && (strlen($myPrivateKey) == 56)) {
      fwrite($gravar,'
stellar_keypair = Keypair.from_secret("' . $myPrivateKey . '")
account_priv_key = stellar_keypair.secret
tx.sign(account_priv_key)
response = server.submit_transaction(tx)
print(response["successful"], response["id"])');
   }
   else {
      fwrite($gravar,'
print(tx.to_xdr())');
   }
   system("python3 $caminho");
   unlink($caminho);
}

function secondsToTime($apartir_quando) {
  // From https://stackoverflow.com/a/19680778
   $ts_now=time();
   $seconds=$apartir_quando-$ts_now;
   $dtF = new \DateTime('@0');
   $dtT = new \DateTime("@$seconds");
   return $dtF->diff($dtT)->format('%ad, %hh %im %ss');
}


?>