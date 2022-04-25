import time
import locale
import requests
from datetime import datetime
import requests.packages.urllib3.exceptions
from stellar_sdk import Server, Keypair, MuxedAccount, TransactionBuilder, Network, TimeBounds, FeeBumpTransaction, ClaimPredicate, Claimant, Asset

requests.packages.urllib3.disable_warnings(requests.packages.urllib3.exceptions.InsecureRequestWarning)


headers_gerais = {
    'user-agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/101.0.0.0 Safari/537.36',
    'accept-language': 'pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
    'accept': '*/*',
    'accept-encoding': 'gzip, deflate, br',
    'connection': 'keep-alive'
}

max_trustlines = 10


def number_format(num, places=0):
    return locale.format_string('%.*f', (places, num), True)

def check_cotacao(org_code, org_issuer, qtd, minimo, dest_code, dest_issuer=None):
    if (org_code == 'XLM'):
        org_type = 'native'

    elif len(org_code) <= 4:
        org_type = 'credit_alphanum4'
    else:
        org_type = 'credit_alphanum12'

    if dest_code == 'XLM':
        cotacao_url = f'https://horizon.stellar.org/paths/strict-send?destination_assets=native&source_asset_type={org_type}&source_asset_code={org_code}&source_asset_issuer={org_issuer}&source_amount={qtd}'
    else:
        cotacao_url = f'https://horizon.stellar.org/paths/strict-send?destination_assets={dest_code}%3A{dest_issuer}&source_asset_type={org_type}&source_asset_issuer={org_issuer}&source_asset_code={org_code}&source_amount={qtd}'

    get_cotacao = requests.get(
        url=cotacao_url,
        headers=headers_gerais,
        timeout=60,
        verify=False
    )

    cotacao = get_cotacao.json()

    # print(cotacao)
    
    if len(cotacao) > 0:
        # print(cotacao['_embedded']['records'][0]['destination_amount'])
        # print(number_format(0.0000100, 7), minimo)
        valor_mercado = cotacao['_embedded']['records'][0]['destination_amount']

        if float(valor_mercado) > minimo:
            valor_mercado = number_format(float(valor_mercado), 7)

            print(f' 💰{valor_mercado} {dest_code}', end='')

            return valor_mercado
        else:
            valor_mercado = number_format(float(valor_mercado), 7)

            print(f' 🧨{valor_mercado} {dest_code}')

            return 0
    else:
      return 0

def proceed_trans(account, trusts, claims, valores_claims, myPrivateKey=None):
    # print(account, trusts, claims, valores_claims, myPrivateKey)

    server = Server('https://horizon.stellar.org')
    print('Gerando XDR...')

    base_fee = 120

    muxed = MuxedAccount(account_id=account, account_muxed_id=1)
    print(muxed.account_muxed)
    muxed = MuxedAccount.from_account(muxed.account_muxed)
    
    account = server.load_account(account)

    print(f'account_id: {muxed.account_id}\naccount_muxed_id: {muxed.account_muxed_id}')

    native_asset = Asset('XLM')

    path = {}
    assets = {}

    for key, clx in valores_claims.items():
        if clx['dest_code'] == 'XLM' or clx['dest_code'] == '' or 'dest_code' not in clx:
            asset_to_sell = Asset(clx['org_code'], clx['org_issuer'])
            path_payments = Server.strict_send_paths(server, source_asset=asset_to_sell, source_amount=clx['org_valor'], destination=[native_asset]).call()
            path[key] = [Asset('XLM') for asset in path_payments['_embedded']['records']]
        else:
            asset_to_sell = Asset(clx['org_code'], clx['org_issuer'])
            path_payments = Server.strict_send_paths(server, source_asset=asset_to_sell, source_amount=clx['org_valor'], destination=[native_asset]).call()
            path[key] = [asset_to_buy for asset in path_payments['_embedded']['records']]

    transaction = TransactionBuilder(
       source_account=account,
       network_passphrase=Network.PUBLIC_NETWORK_PASSPHRASE,
       base_fee=base_fee
    )

    transaction.add_time_bounds(int(time.time()) - 60, int(time.time()) + 300)

    for key, tline in trusts.items():
        asset = tline.split(':')

        assets[asset[0]] = Asset(asset[0], asset[1])
        
        transaction.append_change_trust_op(asset=assets[asset[0]])

    for claim in claims:
        transaction.append_claim_claimable_balance_op(balance_id=claim, source=muxed)

    d = 0
    for key, clx in valores_claims.items():
        if clx['dest_code'] == 'XLM':
            clx['dest_code'] = 'XLM'
            clx['dest_issuer'] = None
            clx['dest_min'] = '0.0001'
        else:
            clx['dest_issuer'] = clx['dest_issuer']

        assets[clx['org_code']] = Asset(clx['org_code'], clx['org_issuer'])
        assets[clx['dest_code']] = Asset(clx['dest_code'], clx['dest_issuer'])

        transaction.append_path_payment_strict_send_op(
            destination=muxed, # account,
            send_asset=assets[clx['org_code']],
            send_amount=clx['org_valor'],
            dest_asset=assets[clx['dest_code']],
            dest_min=clx['dest_min'],
            path=path[key]
        )
        
        transaction.append_change_trust_op(
            asset=assets[clx['org_code']],
            limit='0'
        )

    tx = transaction.build()

    if myPrivateKey[0:1] == 'S' and len(myPrivateKey) == 56:
        stellar_keypair = Keypair.from_secret(myPrivateKey)
        account_priv_key = stellar_keypair.secret
        tx.sign(account_priv_key)
        response = server.submit_transaction(tx)
    
        print('Funcionou?', response['successful'], response['id'])
    else:
        print(tx.to_xdr())


def verificar_conta(public_address, private_address):
    if public_address[0:1] != 'G' or len(public_address) != 56:
        print('Não é um endereço de carteira.')

        exit()

    if private_address[0:1] != 'S' or len(private_address) != 56:
        print('Não é um endereço de carteira privada.')

        exit()
        
    wlt = '[' + public_address[-10:] + ']'
    arrecadacao = 0
    
    get_claimable = requests.get(
        url=f'https://horizon.stellar.org/claimable_balances/?limit=200&claimant={public_address}',
        headers=headers_gerais,
        timeout=60,
        verify=False
    )

    claimable_data = get_claimable.json()

    claimable_records = claimable_data['_embedded']['records']

    if len(claimable_records) < 1:
        print(f'\n{wlt} 0️⃣')
    else:
        trustlines = {}
        ids_claims = []
        valor_claims = {}

        get_account = requests.get(
            url=f'https://horizon.stellar.org/accounts/{public_address}',
            headers=headers_gerais,
            timeout=60,
            verify=False
        )

        account_data = get_account.json()

        account_balances = account_data['balances']

        print(f'\n{wlt}')

        for claimable in claimable_records:
            asset = claimable['asset'].split(':')
            print(' (' + asset[0] + ') ', end='')
            
            possui_trustline = False

            for balance in account_balances:
                if 'asset_code' in balance and balance['asset_code'] == asset[0] and 'asset_issuer' in balance and balance['asset_issuer'] == asset[1]:
                    print('☑', end='')
                    # print('Já tem trustline: ' + claimable["id"] + '\n');
                    possui_trustline = True

                    break

            pode_claim = False
            claimants = claimable['claimants']

            for claimant in claimants:
                if claimant['destination'] == public_address:
                    if 'unconditional' in claimant['predicate'] and claimant['predicate']['unconditional']:
                        print('☑ (incondicional)', end='')
                        # print('❎', end='')
                        pode_claim = True
                    elif 'abs_before' in claimant['predicate']:
                        ate_quando = datetime.fromisoformat(claimant['predicate']['abs_before'].replace('Z', ''))
                        
                        if ate_quando < datetime.now():
                            pode_claim = False
                            print('❎ (expirou)', end='')
                        else:
                            pode_claim = True
                            print('☑ (valido)', end='')

                    if pode_claim:
                        get_asset = requests.get(
                            url=f'https://horizon.stellar.org/assets?asset_code={asset[0]}&asset_issuer={asset[1]}',
                            headers=headers_gerais,
                            timeout=60,
                            verify=False
                        )

                        asset_data = get_asset.json()

                        asset_records = asset_data['_embedded']['records'][0]

                        if not asset_records['flags']['auth_required']:
                            pode_claim = True
                        else:
                            print('✋ (REQUER AUTORIZACAO!)')
                            pode_claim = False
            if not pode_claim:
                print(' (nao pode claim)')

            if not possui_trustline and pode_claim:
                if len(trustlines) < max_trustlines:
                    valor_mercado = check_cotacao(asset[0], asset[1], claimable['amount'], 0.0001, 'XLM', None)

                    if float(valor_mercado) > 0.0001:
                        print('☑ (valido)')
                        arrecadacao += float(valor_mercado)

                        # print('🐧' + claimable['id'])

                        id_asset = claimable['asset'].replace(':', '_')

                        trustlines[id_asset] = claimable['asset']

                        claim_id = claimable['id']

                        ids_claims.append(claim_id)

                        valor_claims[claim_id] = {
                            'org_valor': claimable['amount'],
                            'org_code': asset[0],
                            'org_issuer': asset[1],
                            'dest_code': 'XLM',
                            'valor_mercado': valor_mercado
                        }
                else:
                    print('🧪 (max trustlines')
                    break # TODO: ???????????

    if len(ids_claims) > 0:
        print(f'\n\033[1;33m{wlt} Arrecadacao: {number_format(arrecadacao, 7)}\033[0m\n')
        
        proceed_trans(public_address, trustlines, ids_claims, valor_claims, private_address)
    else:
        print(f'\n\033[1;33m{wlt} 0 Arrecadacao\033[0m\n')


contas = {
    'public_address': 'private_address'
}

if __name__ == '__main__':
    for public_address, private_address in contas.items():
        verificar_conta(public_address, private_address)
