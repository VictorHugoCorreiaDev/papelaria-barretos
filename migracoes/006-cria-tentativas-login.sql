-- Registro das tentativas de login que falharam.
--
-- Serve para limitar força bruta na tela de login. Só o fracasso é gravado;
-- um login bem-sucedido apaga as linhas daquele IP.
--
-- A contagem precisa sobreviver ao navegador, por isso vai para o banco e
-- não para a sessão: quem está tentando adivinhar a senha simplesmente
-- descartaria o cookie a cada tentativa.
--
-- Rodar uma vez em cada ambiente ANTES de publicar o código do login novo.

CREATE TABLE IF NOT EXISTS tentativas_login (
    id INT NOT NULL AUTO_INCREMENT,
    usuario VARCHAR(100) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ip_data (ip, created_at)
) ENGINE=InnoDB;
