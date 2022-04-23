# stellar_autoclaim

Simple scripts to automatize token claiming in the Stellar blockchain using Command Line Interface.

## why

Since AQUA lunch, looks like the use of claimable balances has increased in the Stellar network. Unfortunately it's usage has been abused over the network with both authentic and fake airdrops.

This set of scripts intend to automate the claim process, with or without selling the asset imediately.

## Disclaimer about security

We don't intend to achieve any security standards about storing private keys in this model. In order to abstract any necessary security layers, let's assume that the storage of the private keys will be in a json file containing just pubkey and pvtkey. We don't endorse this usage for production environment cause it's only for learning pruposes.
