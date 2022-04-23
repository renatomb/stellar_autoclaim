<?php

/*
This script claims any claimable balance of an untrusted asset, then imediately trade it for XLM and remove the trustline at the end.
Assets with trustline will be ignored.

In this model we write a python script for each wallet listed in the database.
If there is no private key, unsigned XDR will be print on the screen.
*/

$contas=json_decode(file_get_contents("wallets_and_keys.json"),true);

foreach($contas as $pub => $pvt) {
   verificarConta($pub,$pvt);
}
echo "\n";

function verificarConta($myAddr,$pvt) {
   $arrecadacao=0;
   if ((substr($myAddr,0,1) != "G") || (strlen($myAddr) != 56)) {
      echo "Não é um endereço de carteira.\n\n";
      die;
   }
   $now = new DateTime();
   $claimable = get_page("https://horizon.stellar.org/claimable_balances/?limit=200&claimant=$myAddr");
   $claimable=$claimable["_embedded"]["records"];
   $wlt="[" . substr($myAddr,-10) . "]";
   if (count($claimable) > 0) {
      $trustlines=array();
      $ids_claims=array();
      $account=get_page("https://horizon.stellar.org/accounts/$myAddr");
      $balances=$account["balances"];
      echo "\n$wlt";
      for ($i=0;$i<count($claimable);$i++) {
         $asset=explode(":",$claimable[$i]["asset"]);
         echo " (" . $asset[0] . ") ";
         //Verificar se possui trustline pro asset
         $possui_trustline=false;
         for ($j=0;$j<count($balances);$j++){
            if (($balances[$j]["asset_code"] == $asset[0]) && ($balances[$j]["asset_issuer"] == $asset[1])){
               echo "☑️ ";
               //echo "Já tem trustline: " . $claimable[$i]["id"] . "\n";
               $possui_trustline=true;
            }
         }
         $pode_claim=false;
         $claimants=$claimable[$i]["claimants"];
         for ($k=0;$k<count($claimants);$k++) {
            if ($claimants[$k]["destination"] == $myAddr) {
               if ($claimants[$k]["predicate"]["unconditional"] == 1) {
                  echo "incondicional.";
                  $pode_claim=true;
               }
               elseif (isset($claimants[$k]["predicate"]["abs_before"])) {
                  $ate_quando=new DateTime($claimants[$k]["predicate"]["abs_before"]);
                  if($ate_quando < $now) {
                     $pode_claim=false;
                     echo "expirou.";
                  }
                  else {
                     echo "valido.";
                     $pode_claim=true;
                  }
               }
               if ($pode_claim) {
                  $check_asset=get_page("https://horizon.stellar.org/assets?asset_code=" . $asset[0] . "&asset_issuer=" . $asset[1]);
                  $check_asset=$check_asset["_embedded"]["records"][0];
                  $requer_autorizacao=$check_asset["flags"]["auth_required"];
   //               print_r($check_asset);
                  if (!$requer_autorizacao) {
                     $pode_claim=true;
                  }
                  else {
                     echo " REQUER AUTORIZACAO!";
                     $pode_claim=false;
                  }
               }
            }
         }
         if (!$possui_trustline && $pode_claim) {
/*            if (strlen($asset[0]) <= 4) {
               $ass_type="credit_alphanum4";
            }
            else {
               $ass_type="credit_alphanum12";
            }*/
            if (count($trustlines) < 20) {
               $valor_mercado=check_cotacao($asset[0],$asset[1],$claimable[$i]["amount"],0.0001,"XLM",null);
               /* 
               To check market value against another pair instead of XLM:

               check_cotacao("AssetName","GXXXADDRESSXXXXXOFXXXXXTHEXXXXXISSUERXXXXXXXXXXXXXXXXXXX",10,0.0000001,"LSP","GAB7STHVD5BDH3EEYXPI3OM7PCS4V443PYB5FNT6CFGJVPDLMKDM24WK");

               */
               if ($valor_mercado > 0.0001) {
                  $arrecadacao+=$valor_mercado;
                  echo "🔦" . $claimable[$i]["id"];
                  $id_asset=str_replace(":","_",$claimable[$i]["asset"]);
                  $trustlines[$id_asset]=$claimable[$i]["asset"];
                  $claim_id=$claimable[$i]["id"];
                  $ids_claims[]=$claim_id;
                  $valor_claims[$claim_id]["org_valor"]=$claimable[$i]["amount"];
                  $valor_claims[$claim_id]["org_code"]=$asset[0];
                  $valor_claims[$claim_id]["org_issuer"]=$asset[1];
                  $valor_claims[$claim_id]["dest_code"]="XLM";
                  /*
                  SAMPLE for trading to another asset instead of XLM

                  In our tests din't worked very well because of some spammy assets have low liquidity.

                  $valor_claims[$claim_id]["dest_code"]="LSP";
                  $valor_claims[$claim_id]["dest_issuer"]="GAB7STHVD5BDH3EEYXPI3OM7PCS4V443PYB5FNT6CFGJVPDLMKDM24WK";
                  $valor_claims[$claim_id]["dest_min"]=number_format((0.0001/check_cotacao("LSP","GAB7STHVD5BDH3EEYXPI3OM7PCS4V443PYB5FNT6CFGJVPDLMKDM24WK",1,0.0001,"XLM",null)),7,".","");

                  */
               }
/*

Another way to check the asset value is via orderbook.

Sample code:

               $cotacao=get_page("https://horizon.stellar.org/order_book?selling_asset_type=$ass_type&selling_asset_code=" . $asset[0] . "&selling_asset_issuer=" . $asset[1] . "&buying_asset_type=native");
               $cotacao=$cotacao["bids"];
               if (count($cotacao) > 0) {
                  $preco = $cotacao[0]["price"];
                  $valor_mercado=$claimable[$i]["amount"]*$preco;
                  if ($valor_mercado >= 0.0001) {
                     echo "💲 $preco XLM x " . $claimable[$i]["amount"] . " = $valor_mercado XLM\n";
                     echo $claimable[$i]["asset"] . " - " . $claimable[$i]["id"];
                     $id_asset=str_replace(":","_",$claimable[$i]["asset"]);
                     $trustlines[$id_asset]=$claimable[$i]["asset"];
                     $claim_id=$claimable[$i]["id"];
                     $ids_claims[]=$claim_id;
                     $valor_claims[$claim_id]["org_valor"]=$claimable[$i]["amount"];
                     $valor_claims[$claim_id]["org_code"]=$asset[0];
                     $valor_claims[$claim_id]["org_issuer"]=$asset[1];
                  }
                  else {
                     echo "📉 no value!";
                  }
               }
               else {
                  echo $asset[0] . " 🔜 no offers.";
               }
*/
            }
            else {
               echo "🧪 max.";
            }
         }
      }
   }
   else {
      echo "\n$wlt 0️⃣";
   }
   if (count($ids_claims) > 0) {
      echo "\n\033[1;33m$wlt Earnings: " . number_format($arrecadacao,7,".","") ."\033[0m";
      proceed_trans($myAddr,$trustlines,$ids_claims,$valor_claims, $pvt);
   }
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

function check_cotacao($org_code,$org_issuer,$qtd,$minimo,$dest_code,$dest_issuer=null){
   if ($org_code == "XLM") {
      $org_type="native";
   }
   elseif (strlen($org_code) <= 4) {
      $org_type="credit_alphanum4";
   }
   else {
      $org_type="credit_alphanum12";
   }
   switch ($dest_code) {
      case "XLM":
         $cotacao=get_page("https://horizon.stellar.org/paths/strict-send?destination_assets=native&source_asset_type=$org_type&source_asset_code=$org_code&source_asset_issuer=$org_issuer&source_amount=$qtd");
         break;
      default:
         $cotacao=get_page("https://horizon.stellar.org/paths/strict-send?destination_assets=$dest_code%3A$dest_issuer&source_asset_type=$org_type&source_asset_issuer=$org_issuer&source_asset_code=$org_code&source_amount=$qtd");
         break;
   }
   if (count($cotacao) > 0) {
      $valor_mercado=$cotacao["_embedded"]["records"][0]["destination_amount"];
      if ($valor_mercado > $minimo) {
         $valor_mercado=number_format($valor_mercado,7);
         echo "💰$valor_mercado $dest_code ";
         return $valor_mercado;
      }
      else {
         echo "🧨$valor_mercado $dest_code ";
      }
   }
   else {
      return 0;
   }
}

function proceed_trans($account,$trusts,$claims,$valor_claims, $myPrivateKey=null) {
   //Path for the temporary python generated file below:
   $caminho="/Users/renato/Downloads/exec_" . uniqid() . ".py";

   $gravar=fopen($caminho,"w+");
   fwrite($gravar,'from stellar_sdk import Server, Keypair, TransactionBuilder, Network, FeeBumpTransaction, ClaimPredicate, Claimant, Asset
import requests

# 1. Load Keys
server = Server("https://horizon.stellar.org")
print("Gerando XDR...")

base_fee = 120
account = server.load_account("' . $account . '")

native_asset = Asset("XLM")
tgbot_asset = Asset("TelegramBOT", "GALOGON2G373SBNAH2VOWJFKK5EO4OF4CGEFB4CG3ZY4ZYUSNIPTGBOT")
');
   foreach ($valor_claims as $key => $clx) {
      if (!isset($clx["dest_code"]) || empty($clx["dest_code"]) || ($clx["dest_code"] == "XLM")) {
         fwrite($gravar,'asset_to_sell = Asset("' . $clx["org_code"] . '", "' . $clx["org_issuer"] . '")
path_payments = Server.strict_send_paths(server, source_asset=asset_to_sell, source_amount="' . $clx["org_valor"] . '", destination=[native_asset]).call()
path_' . $key . ' = [Asset("XLM") for asset in path_payments["_embedded"]["records"]]
');
      }
      else {
         fwrite($gravar,'asset_to_buy = Asset("' . $clx["dest_code"] .'", "' . $clx["dest_issuer"] . '")
asset_to_sell = Asset("' . $clx["org_code"] . '", "' . $clx["org_issuer"] . '")
path_payments = Server.strict_send_paths(server, source_asset=asset_to_sell, source_amount="' . $clx["org_valor"] . '", destination=[asset_to_buy]).call()
path_' . $key . ' = [asset_to_buy for asset in path_payments["_embedded"]["records"]]
');
      }
   }
   fwrite($gravar,'transaction = TransactionBuilder(
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
   foreach ($valor_claims as $key => $clx) {
      if ($clx["dest_code"] == "XLM") {
         $clx["dest_code"]="XLM";
         $clx["dest_issuer"]="None";
         $clx["dest_min"]="0.0001";
      }
      else { $clx["dest_issuer"]='"' . $clx["dest_issuer"] . '"'; }
      fwrite($gravar,'.append_path_payment_strict_send_op(
   destination="' . $account . '",
   send_code="' . $clx["org_code"] . '", 
   send_issuer="' . $clx["org_issuer"] . '", 
   send_amount="' . $clx["org_valor"] . '",
   dest_code="' . $clx["dest_code"] . '",
   dest_issuer=' . $clx["dest_issuer"] . ',
   dest_min="' . $clx["dest_min"] . '",
   path=path_' . $key . '
).append_change_trust_op(
   asset_code="' . $clx["org_code"] . '", asset_issuer="' . $clx["org_issuer"] . '",limit="0"
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

?>