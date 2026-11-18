# TD Notas

Plugin WordPress da Tecla Digital para notas visuais em sites em desenvolvimento: o cliente clica num elemento da página, deixa uma nota, responde, anexa ficheiros e acompanha cada correção pelos estados (Aberta → Em curso → Para verificar → Para a TD rever → Fechada). Funciona com qualquer page builder, só em computador (ecrãs com 1024 px ou mais).

## Instalação

Instalar o zip do plugin (com a pasta `td-notas/` lá dentro) em **Plugins → Adicionar → Carregar plugin**.

## Atualizações

A partir da versão 1.7.0, o WordPress deteta as versões novas publicadas aqui e mostra-as em **Plugins** e **Painel → Atualizações**.

Para publicar uma versão nova:

1. Subir a versão no cabeçalho de `td-notas.php` e na constante `TD_NOTAS_VERSAO`.
2. Enviar o código para `main` e criar a etiqueta `vX.Y.Z` (`git tag vX.Y.Z && git push origin vX.Y.Z`).

O WordPress procura a etiqueta com a versão mais alta e instala o zip que o GitHub gera para ela.

## Acesso

- Administradores: acesso automático.
- Outros utilizadores: caixa «Pode usar TD Notas» no perfil.

O histórico de versões está no início de `td-notas.php`.
