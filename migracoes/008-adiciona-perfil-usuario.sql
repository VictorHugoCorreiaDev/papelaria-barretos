-- Perfil de acesso do usuário: 'admin' ou 'vendedor'.
--
-- Os usuários que já existem recebem 'admin' pelo DEFAULT: até aqui todos
-- viam tudo, e ninguém pode perder acesso com a chegada dos perfis. Os
-- novos são criados pela tela de Usuários, que escolhe o perfil.
--
-- Rodar uma vez em cada ambiente ANTES de publicar o código: a verificação
-- de sessão lê esta coluna a cada acesso, e sem ela nenhuma página abre.

ALTER TABLE usuarios ADD COLUMN perfil VARCHAR(20) NOT NULL DEFAULT 'admin';
