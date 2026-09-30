# Operação do wazeBR-symfony

## Objetivo

Esta pasta reúne o mapa operacional do sistema: quais recursos existem, onde
estão implementados, quais dados utilizam e como verificar seu funcionamento.

## Documentos

| Arquivo | Finalidade |
| --- | --- |
| `ARQUITETURA-E-RECURSOS.md` | Mapa dos módulos e pontos de entrada no código. |
| `DICIONARIO-DE-DADOS.md` | Tabelas, finalidade e campos importantes. |
| `RELACIONAMENTOS.md` | Relações entre entidades e verificações de integridade. |
| `COLETAS-E-AGENDAMENTO.md` | Inventário de comandos e fluxo de coleta. |
| `MONITORAMENTO-E-VALIDACAO.md` | Checklist e consultas de saúde dos dados. |
| `SEGURANCA-E-MULTITENANCY.md` | Isolamento por parceiro e proteção de credenciais. |
| `MANUTENCAO-E-PENDENCIAS.md` | Rotina de manutenção e pontos a confirmar. |

## Fontes e limites

- Código: estrutura observada no repositório `andresdiniz/wazeBR-symfony`.
- Banco: estrutura de `u629736858_trafik` fornecida pelo proprietário.
- Este material não é um laudo de funcionamento em produção.
- Nomes de classes e tabelas foram conferidos; periodicidades, contratos de API,
  rotas exatas, constraints e políticas de retenção exigem validação adicional.
- Antes de executar SQL de diagnóstico, confirme ambiente, fuso horário e
  permissões. As consultas deste conjunto são somente de leitura.

## Ordem recomendada de uso

1. Localize o recurso em `ARQUITETURA-E-RECURSOS.md`.
2. Identifique suas tabelas em `DICIONARIO-DE-DADOS.md`.
3. Consulte `RELACIONAMENTOS.md` antes de alterar dados ou migrations.
4. Confira o comando e o mecanismo de execução em `COLETAS-E-AGENDAMENTO.md`.
5. Faça os testes de `MONITORAMENTO-E-VALIDACAO.md`.
6. Registre problemas e decisões em `MANUTENCAO-E-PENDENCIAS.md`.
