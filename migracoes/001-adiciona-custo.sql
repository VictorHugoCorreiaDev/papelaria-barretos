-- Adiciona o custo dos produtos, para o dashboard poder calcular lucro.
--
-- Rodar uma vez em cada ambiente (local e produção) ANTES de publicar o
-- código que usa estas colunas.

-- Custo atual de compra do produto.
ALTER TABLE produtos
    ADD COLUMN custo DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER preco;

-- Custo congelado no momento da venda, pelo mesmo motivo do preco_unitario:
-- alterar o custo de um produto não pode reescrever o lucro de vendas
-- passadas.
ALTER TABLE vendas_produtos
    ADD COLUMN custo_unitario DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER preco_unitario;
