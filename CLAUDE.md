# Site MAB (mab.free.nf) — Regras do projeto

Site de gestão em PHP, alojado no InfinityFree (ficheiros em `htdocs`, geridos via filemanager.ai).
Escreve sempre em português de Portugal.

## 1. Regras de Ouro (código PHP)

Estas regras ficam assinaladas nos comentários do código com `REGRA DE OURO`.

1. **Validação de sessão**: se não houver sessão iniciada (`$_SESSION['user_id']`), a página redireciona para `index.php` e termina (`exit`).
2. **Acesso restrito pela matriz de permissões**: só entram utilizadores admin/administrativo ou com o módulo autorizado na tabela `permissoes`.
3. **Primeiro nome no menu**: o menu do utilizador mostra só o primeiro nome.
4. **PDFs limpos**: chamar `ob_start()` no início do ficheiro e `ob_end_clean()` antes de gerar o PDF, para que espaços ou avisos não o estraguem.
5. **Validação rigorosa do ID**: qualquer ID que chegue pelo endereço (GET) é validado como número inteiro (ex.: `filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT)`) antes de ser usado.
6. **Numerador global**: visita, orçamento, obra e termo partilham o mesmo número, com o formato AA + sequência (mínimo AA0100). O último número usado fica em `configuracoes.ultimo_numero_projeto`.
7. **Token CSRF**: todos os formulários POST incluem `csrf_token` e o servidor valida-o.
8. **Ícone MAB no separador**: todas as páginas mostram o ícone `mab.png` (raiz do servidor) no separador do navegador. No `<head>` de cada página, usar sempre caminho absoluto (com `/` inicial) e nunca `favicon.ico`:
   ```html
   <link rel="icon" type="image/png" href="/mab.png">
   <link rel="apple-touch-icon" href="/mab.png">
   ```

## 2. Regras de trabalho e de interface

- **Ficheiros completos**: quando alterar código, entregar sempre o ficheiro completo, pronto a colar no filemanager.ai (nunca só excertos).
- **Páginas adaptáveis**: funcionam em computador, tablet e telemóvel. No telemóvel, as tabelas passam a cartões.
- **Logótipo**: o logótipo MAB, no canto superior esquerdo, leva sempre a `index.php`.
- **Cabeçalho**: sem ícones de navegação rápida.
- **Botão voltar**: redondo, só com a seta, sem texto.
- **Menu do utilizador**: abre com um toque em ecrãs táteis (não depender de hover).
- **Pesquisa de material**: em todas as pesquisas / listas de escolha de artigos ou produtos, cada opção aparece sempre como
  `descrição | referência do fabricante | marca` (partes vazias omitidas). A pesquisa encontra por qualquer uma das três.
- **Nunca perder dados**: ao alterar, substituir ou "apagar", guardar histórico ou arquivar. Os apagamentos pedem a password de segurança.
- **Nome dos PDFs de orçamento**: sempre `ORC{número}.pdf`.
- **Instalações elétricas**: os cálculos respeitam sempre as RTIEBT.
- **SADI, iluminação de emergência e gás**: aplicar as Notas Técnicas da ANEPC e perguntar ao utilizador antes de decidir.

## 3. Páginas antigas

Algumas páginas antigas ainda não cumprem as regras de interface (por exemplo, o botão voltar com texto).
Sempre que for pedida uma alteração a uma página, corrigir também essa página para cumprir todas as regras acima.

## 4. Segurança

- Nunca pôr passwords, utilizadores da base de dados ou chaves no repositório nem no chat. Usar `'***'` como substituto.
- Usar prepared statements em todas as consultas SQL.
- Escapar todo o output com `htmlspecialchars()`.
