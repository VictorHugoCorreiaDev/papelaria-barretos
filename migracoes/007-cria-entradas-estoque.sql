-- Entradas de estoque: cada compra ou reposição de mercadoria.
--
-- Antes, repor estoque era editar o produto e digitar a quantidade nova por
-- cima da antiga: não ficava registro de quando entrou, quanto entrou, de
-- quem foi comprado nem por quanto. Esta tabela guarda cada entrada; a
-- quantidade em produtos continua sendo o saldo corrente.
--
-- Só produto e quantidade são obrigatórios. Custo e fornecedor ficam
-- opcionais para a entrada rápida ("chegaram 10 cadernos") continuar
-- sendo um lançamento de dois campos.
--
-- Rodar uma vez em cada ambiente ANTES de publicar o código da tela.

CREATE TABLE IF NOT EXISTS entradas_estoque (
    id INT NOT NULL AUTO_INCREMENT,
    produto_id INT NOT NULL,
    quantidade INT NOT NULL,
    custo_unitario DECIMAL(10,2) DEFAULT NULL,
    fornecedor VARCHAR(120) DEFAULT NULL,
    data_entrada DATE NOT NULL,
    observacao VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_entradas_data (data_entrada),
    KEY idx_entradas_produto (produto_id)
) ENGINE=InnoDB;
