-- Desconto concedido na venda.
--
-- Rodar uma vez em cada ambiente (local e produção) ANTES de publicar o
-- código que usa esta coluna.
--
-- O campo `total` continua sendo o valor LÍQUIDO, o que de fato entrou no
-- caixa: é ele que alimenta faturamento e lucro em todas as telas, e
-- mantê-lo líquido faz esses números ficarem certos sem nenhuma outra
-- alteração. O valor bruto, quando precisar, é total + desconto.

ALTER TABLE vendas
    ADD COLUMN desconto DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER total;
