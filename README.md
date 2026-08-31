# 🛍️ Papelaria Barretos

![PHP](https://img.shields.io/badge/PHP-8+-blue?logo=php)
![MySQL](https://img.shields.io/badge/MySQL-Database-orange?logo=mysql)
![Status](https://img.shields.io/badge/Status-Em%20Desenvolvimento-brightgreen)
![License](https://img.shields.io/badge/License-MIT-lightgrey)
![Version](https://img.shields.io/badge/Version-1.0.0-blue)

Sistema web para gerenciamento de vendas, estoque e relatórios de uma papelaria.

---

## 🚀 Funcionalidades

- 🔐 Autenticação de usuário (login em todas as telas)
- 📦 Cadastro de produtos
- ✏️ Edição e exclusão de produtos
- 📊 Controle de estoque
- 🛒 Registro de vendas com carrinho
- ⚡ Venda rápida com AJAX, direto do dashboard
- 📄 Listagem de vendas com filtro por situação
- ↩️ Cancelamento de venda, com devolução ao estoque
- 📈 Relatórios por período

---

## 🛠️ Tecnologias Utilizadas

- PHP 8+
- MySQL
- HTML5
- CSS3
- JavaScript
- AJAX

Sem framework, sem Composer e sem etapa de build — basta o PHP e o banco.

---

## 💻 Como Executar Localmente

### 1️⃣ Clone o repositório

```bash
git clone https://github.com/HavocC-marcha/papelaria-barretos.git
```

### 2️⃣ Acesse a pasta

```bash
cd papelaria-barretos
```

### 3️⃣ Configure o banco

Veja a seção [Configuração do Banco de Dados](#️-configuração-do-banco-de-dados) abaixo.

### 4️⃣ Inicie o servidor

```bash
php -S localhost:8000
```

> ⚠️ O servidor precisa rodar a partir da **raiz do projeto**. A navegação, o CSS e as chamadas AJAX usam caminhos absolutos (`/pages/...`, `/assets/...`), então servir de um subdiretório quebra o sistema.

### 5️⃣ Acesse no navegador

```
http://localhost:8000
```

---

## 🗄️ Configuração do Banco de Dados

### 1️⃣ Crie o banco e as tabelas

```sql
CREATE DATABASE barretur CHARACTER SET utf8mb4;
USE barretur;

CREATE TABLE usuarios (
  id INT NOT NULL AUTO_INCREMENT,
  usuario VARCHAR(100) NOT NULL,
  senha VARCHAR(255) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY (usuario)
) ENGINE=InnoDB;

CREATE TABLE produtos (
  id INT NOT NULL AUTO_INCREMENT,
  nome VARCHAR(255) NOT NULL,
  preco DECIMAL(10,2) NOT NULL,
  quantidade INT NOT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB;

CREATE TABLE vendas (
  id INT NOT NULL AUTO_INCREMENT,
  total DECIMAL(10,2) NOT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  status VARCHAR(20) DEFAULT 'ativa',
  PRIMARY KEY (id)
) ENGINE=InnoDB;

CREATE TABLE vendas_produtos (
  id INT NOT NULL AUTO_INCREMENT,
  venda_id INT DEFAULT NULL,
  produto_id INT DEFAULT NULL,
  quantidade INT DEFAULT NULL,
  preco_unitario DECIMAL(10,2) DEFAULT NULL,
  PRIMARY KEY (id),
  KEY (venda_id),
  KEY (produto_id),
  FOREIGN KEY (venda_id) REFERENCES vendas (id),
  FOREIGN KEY (produto_id) REFERENCES produtos (id)
) ENGINE=InnoDB;
```

Detalhes que valem conhecer:

- `vendas.status` guarda `'ativa'` ou `'cancelada'`. Vendas **nunca são excluídas**, apenas marcadas como canceladas — o histórico é preservado.
- `vendas_produtos.preco_unitario` congela o preço no momento da venda, de modo que alterar o preço de um produto não altera totais de vendas antigas.
- As chaves estrangeiras não têm `ON DELETE CASCADE` de propósito: um produto que já foi vendido não pode ser excluído, e a tela de estoque avisa quando isso acontece.

### 2️⃣ Crie o arquivo de conexão

O `Conexao.php` não vem no repositório porque guarda a senha do banco. Copie o modelo e preencha com os dados locais:

```bash
cp Conexao.exemplo.php Conexao.php
```

### 3️⃣ Crie o primeiro usuário

Não existe tela de cadastro — o usuário precisa ser inserido manualmente, e a senha é um hash bcrypt. Gere o hash:

```bash
php -r "echo password_hash('sua_senha', PASSWORD_DEFAULT), PHP_EOL;"
```

E insira no banco, colando o hash gerado:

```sql
INSERT INTO usuarios (usuario, senha) VALUES ('admin', 'cole_o_hash_aqui');
```

---

## 📁 Organização

```
├── ajax/          endpoints chamados por fetch (retornam JSON ou HTML parcial)
├── assets/        css, js e imagens
├── includes/      auth, header, sidebar, footer e validações compartilhadas
├── pages/         telas do sistema
├── dashboard.php  indicadores e venda rápida
└── login.php      única rota pública
```

Todas as demais rotas exigem sessão ativa. O `CLAUDE.md` documenta a arquitetura em detalhe: ordem dos includes, convenções entre páginas e regras de escape de saída.

---

## 🌍 Deploy

Atualmente hospedado em ambiente gratuito utilizando InfinityFree.

---

## 👨‍💻 Autor

Victor Hugo Batista Correia
