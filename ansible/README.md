# Ansible Playbook for the Kirby Demo Manager

## Installation

Ensure that you have a recent Ansible version installed (tested with 3.0.0+).

## Commands

```sh
# execute everything on all hosts
ansible-playbook -v --ask-vault-pass site.yml

# execute everything only on staging
ansible-playbook -v --ask-vault-pass --limit staging site.yml

# execute everything only on production
ansible-playbook -v --ask-vault-pass --limit production site.yml
```
