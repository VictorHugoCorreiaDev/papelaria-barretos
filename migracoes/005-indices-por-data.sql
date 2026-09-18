-- Índices de data em vendas e despesas.
--
-- Praticamente toda tela filtra por período: o dashboard recorta o mês, o
-- relatório e o fechamento de caixa recebem duas datas, e a lista de vendas
-- agrupa por dia. Sem índice, cada um desses filtros faz o MySQL varrer a
-- tabela inteira. Com poucas centenas de linhas ninguém percebe; o custo
-- cresce junto com o histórico, que é justamente o que nunca para de crescer.
--
-- A tabela despesas nasceu fora do sistema no banco de desenvolvimento, sem
-- o índice que o 004 declara — por isso ele também aparece aqui. Onde o 004
-- criou a tabela (produção, por exemplo), o índice já existe com o nome
-- data_despesa: rode só a linha de vendas. Se rodar as duas, o MySQL cria um
-- índice repetido; remova com
--   ALTER TABLE despesas DROP INDEX idx_despesas_data;
--
-- O MySQL 8 não tem "ADD INDEX IF NOT EXISTS". Se o índice já existir, o
-- comando falha com "Duplicate key name": é seguro ignorar esse erro e
-- seguir para o próximo.

ALTER TABLE vendas   ADD INDEX idx_vendas_data (created_at);
ALTER TABLE despesas ADD INDEX idx_despesas_data (data_despesa);
