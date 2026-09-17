-- Despesas do negócio (aluguel, energia, fornecedores etc).
--
-- A tabela já existia no banco de desenvolvimento, criada fora do sistema;
-- este arquivo garante que ela exista nos demais ambientes com a mesma
-- estrutura. Rodar uma vez em cada ambiente ANTES de publicar o código.
--
-- O IF NOT EXISTS torna a execução segura onde a tabela já está criada.

CREATE TABLE IF NOT EXISTS despesas (
    id INT NOT NULL AUTO_INCREMENT,
    descricao VARCHAR(255) NOT NULL,
    categoria VARCHAR(100) NOT NULL,
    valor DECIMAL(10,2) NOT NULL,
    data_despesa DATE NOT NULL,
    forma_pagamento VARCHAR(50) DEFAULT NULL,
    observacao TEXT,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY (data_despesa)
) ENGINE=InnoDB;
