-- Registra quem comprou e como pagou, para o painel de últimas vendas.
--
-- Rodar uma vez em cada ambiente (local e produção) ANTES de publicar o
-- código que usa estas colunas.

ALTER TABLE vendas
    ADD COLUMN cliente VARCHAR(120) NOT NULL DEFAULT '' AFTER total,
    ADD COLUMN forma_pagamento VARCHAR(20) NOT NULL DEFAULT '' AFTER cliente;
