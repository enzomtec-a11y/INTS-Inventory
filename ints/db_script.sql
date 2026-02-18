-- MySQL Workbench Forward Engineering

SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0;
SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;
SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

-- -----------------------------------------------------
-- Schema mydb
-- -----------------------------------------------------
-- -----------------------------------------------------
-- Schema ints_db
-- -----------------------------------------------------

-- -----------------------------------------------------
-- Schema ints_db
-- -----------------------------------------------------
CREATE SCHEMA IF NOT EXISTS `ints_db` DEFAULT CHARACTER SET utf8mb4 ;
USE `ints_db` ;

-- -----------------------------------------------------
-- Table `ints_db`.`usuarios`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `ints_db`.`usuarios` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `nome` VARCHAR(150) NOT NULL,
  `email` VARCHAR(100) NOT NULL,
  `nivel` ENUM('comum', 'gestor', 'admin', 'admin_unidade') NOT NULL DEFAULT 'comum',
  `unidade_id` INT(11) NULL DEFAULT NULL,
  `senha_hash` VARCHAR(255) NOT NULL,
  `ativo` TINYINT(1) NULL DEFAULT 1,
  `data_criado` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  UNIQUE INDEX `email` (`email` ASC) VISIBLE,
  INDEX `idx_usuarios_unidade` (`unidade_id` ASC) VISIBLE)
ENGINE = InnoDB
AUTO_INCREMENT = 9
DEFAULT CHARACTER SET = latin1;


-- -----------------------------------------------------
-- Table `ints_db`.`locadores`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `ints_db`.`locadores` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `nome` VARCHAR(200) NOT NULL,
  `razao_social` VARCHAR(200) NULL DEFAULT NULL,
  `cnpj` VARCHAR(18) NULL DEFAULT NULL,
  `cpf` VARCHAR(14) NULL DEFAULT NULL,
  `tipo_pessoa` ENUM('juridica', 'fisica') NOT NULL DEFAULT 'juridica',
  `email` VARCHAR(150) NULL DEFAULT NULL,
  `telefone` VARCHAR(20) NULL DEFAULT NULL,
  `celular` VARCHAR(20) NULL DEFAULT NULL,
  `endereco` VARCHAR(300) NULL DEFAULT NULL,
  `cidade` VARCHAR(100) NULL DEFAULT NULL,
  `estado` VARCHAR(2) NULL DEFAULT NULL,
  `cep` VARCHAR(10) NULL DEFAULT NULL,
  `contato_responsavel` VARCHAR(150) NULL DEFAULT NULL COMMENT 'Nome do responsável/representante',
  `observacoes` TEXT NULL DEFAULT NULL,
  `ativo` TINYINT(1) NULL DEFAULT 1,
  `criado_por` INT(11) NULL DEFAULT NULL,
  `data_criado` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `data_atualizado` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  INDEX `idx_nome` (`nome` ASC) VISIBLE,
  INDEX `idx_cnpj` (`cnpj` ASC) VISIBLE,
  INDEX `idx_cpf` (`cpf` ASC) VISIBLE,
  INDEX `idx_ativo` (`ativo` ASC) VISIBLE,
  INDEX `criado_por` (`criado_por` ASC) VISIBLE,
  CONSTRAINT `locadores_ibfk_1`
    FOREIGN KEY (`criado_por`)
    REFERENCES `ints_db`.`usuarios` (`id`))
ENGINE = InnoDB
AUTO_INCREMENT = 2
DEFAULT CHARACTER SET = utf8mb4
COMMENT = 'Cadastro de locadores/fornecedores';


-- -----------------------------------------------------
-- Table `ints_db`.`contratos_locacao`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `ints_db`.`contratos_locacao` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `locador_id` INT(11) NOT NULL,
  `numero_contrato` VARCHAR(100) NOT NULL,
  `descricao` TEXT NULL DEFAULT NULL COMMENT 'Descrição do que está sendo locado',
  `valor_mensal` DECIMAL(12,2) NULL DEFAULT NULL,
  `valor_total` DECIMAL(12,2) NULL DEFAULT NULL,
  `data_inicio` DATE NOT NULL,
  `data_fim` DATE NULL DEFAULT NULL,
  `data_vencimento_pagamento` INT(11) NULL DEFAULT NULL COMMENT 'Dia do mês para vencimento (1-31)',
  `renovacao_automatica` TINYINT(1) NULL DEFAULT 0,
  `observacoes` TEXT NULL DEFAULT NULL,
  `arquivo_contrato` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Caminho do arquivo PDF do contrato',
  `status` ENUM('ativo', 'vencido', 'cancelado', 'suspenso') NULL DEFAULT 'ativo',
  `criado_por` INT(11) NULL DEFAULT NULL,
  `data_criado` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `data_atualizado` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  UNIQUE INDEX `uk_numero_contrato` (`numero_contrato` ASC) VISIBLE,
  INDEX `idx_locador` (`locador_id` ASC) VISIBLE,
  INDEX `idx_numero` (`numero_contrato` ASC) VISIBLE,
  INDEX `idx_status` (`status` ASC) VISIBLE,
  INDEX `idx_datas` (`data_inicio` ASC, `data_fim` ASC) VISIBLE,
  INDEX `criado_por` (`criado_por` ASC) VISIBLE,
  INDEX `idx_contratos_status_datas` (`status` ASC, `data_inicio` ASC, `data_fim` ASC) VISIBLE,
  INDEX `idx_contratos_locador` (`locador_id` ASC) VISIBLE,
  INDEX `idx_contratos_status` (`status` ASC) VISIBLE,
  CONSTRAINT `contratos_locacao_ibfk_1`
    FOREIGN KEY (`locador_id`)
    REFERENCES `ints_db`.`locadores` (`id`),
  CONSTRAINT `contratos_locacao_ibfk_2`
    FOREIGN KEY (`criado_por`)
    REFERENCES `ints_db`.`usuarios` (`id`))
ENGINE = InnoDB
AUTO_INCREMENT = 3
DEFAULT CHARACTER SET = utf8mb4
COMMENT = 'Contratos de locação ativos e histórico';


-- -----------------------------------------------------
-- Table `ints_db`.`categorias`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `ints_db`.`categorias` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `nome` VARCHAR(100) NOT NULL,
  `descricao` TEXT NULL DEFAULT NULL,
  `categoria_pai_id` INT(11) NULL DEFAULT NULL,
  `deletado` TINYINT(1) NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  INDEX `idx_categorias_pai` (`categoria_pai_id` ASC) VISIBLE,
  INDEX `idx_categorias_hierarquia` (`categoria_pai_id` ASC) VISIBLE,
  CONSTRAINT `categorias_ibfk_1`
    FOREIGN KEY (`categoria_pai_id`)
    REFERENCES `ints_db`.`categorias` (`id`))
ENGINE = InnoDB
AUTO_INCREMENT = 11
DEFAULT CHARACTER SET = latin1;


-- -----------------------------------------------------
-- Table `ints_db`.`produtos`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `ints_db`.`produtos` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `numero_patrimonio` VARCHAR(100) NULL DEFAULT NULL,
  `nome` VARCHAR(100) NOT NULL,
  `descricao` TEXT NULL DEFAULT NULL,
  `categoria_id` INT(11) NOT NULL,
  `local_id_inicial` INT(11) NULL DEFAULT NULL,
  `controla_estoque_proprio` TINYINT(1) NULL DEFAULT 1,
  `tipo_posse` ENUM('proprio', 'locado') NOT NULL DEFAULT 'proprio',
  `locador_id` INT(11) NULL DEFAULT NULL COMMENT 'FK para tabela locadores',
  `locador_nome` VARCHAR(255) NULL DEFAULT NULL,
  `numero_contrato` VARCHAR(100) NULL DEFAULT NULL,
  `locacao_contrato` VARCHAR(100) NULL DEFAULT NULL,
  `contrato_locacao_id` INT(11) NULL DEFAULT NULL COMMENT 'ID do contrato de locação (se produto for locado)',
  `data_criado` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `data_atualizado` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  `deletado` TINYINT(1) NULL DEFAULT 0,
  `status_produto` ENUM('ativo', 'baixa_parcial', 'baixa_total', 'inativo') NOT NULL DEFAULT 'ativo',
  PRIMARY KEY (`id`),
  UNIQUE INDEX `numero_patrimonio` (`numero_patrimonio` ASC) VISIBLE,
  INDEX `idx_produtos_categoria` (`categoria_id` ASC) VISIBLE,
  INDEX `idx_numero_patrimonio` (`numero_patrimonio` ASC) VISIBLE,
  INDEX `idx_local_inicial` (`local_id_inicial` ASC) VISIBLE,
  INDEX `idx_status_produto` (`status_produto` ASC) VISIBLE,
  INDEX `idx_tipo_posse` (`tipo_posse` ASC) VISIBLE,
  INDEX `idx_locador_nome` (`locador_nome` ASC) VISIBLE,
  INDEX `idx_numero_contrato` (`numero_contrato` ASC) VISIBLE,
  INDEX `idx_contrato_locacao` (`contrato_locacao_id` ASC) VISIBLE,
  INDEX `idx_produtos_tipo_posse_status` (`tipo_posse` ASC, `status_produto` ASC) VISIBLE,
  INDEX `idx_produtos_locador` (`locador_id` ASC) VISIBLE,
  INDEX `idx_produtos_contrato` (`contrato_locacao_id` ASC) VISIBLE,
  CONSTRAINT `fk_produto_contrato`
    FOREIGN KEY (`contrato_locacao_id`)
    REFERENCES `ints_db`.`contratos_locacao` (`id`)
    ON DELETE SET NULL,
  CONSTRAINT `fk_produtos_contrato`
    FOREIGN KEY (`contrato_locacao_id`)
    REFERENCES `ints_db`.`contratos_locacao` (`id`)
    ON DELETE SET NULL
    ON UPDATE CASCADE,
  CONSTRAINT `fk_produtos_locador`
    FOREIGN KEY (`locador_id`)
    REFERENCES `ints_db`.`locadores` (`id`)
    ON DELETE SET NULL
    ON UPDATE CASCADE,
  CONSTRAINT `produtos_ibfk_1`
    FOREIGN KEY (`categoria_id`)
    REFERENCES `ints_db`.`categorias` (`id`))
ENGINE = InnoDB
AUTO_INCREMENT = 9
DEFAULT CHARACTER SET = latin1;


-- -----------------------------------------------------
-- Table `ints_db`.`acoes_log`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `ints_db`.`acoes_log` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` INT(11) NOT NULL,
  `tabela_afetada` VARCHAR(100) NOT NULL,
  `registro_id` INT(11) NULL DEFAULT NULL,
  `produto_id` INT(11) NULL DEFAULT NULL,
  `acao` VARCHAR(100) NOT NULL,
  `detalhes` TEXT NULL DEFAULT NULL,
  `data_evento` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  INDEX `produto_id` (`produto_id` ASC) VISIBLE,
  INDEX `idx_acoes_log_usuario_data` (`usuario_id` ASC, `data_evento` ASC) VISIBLE,
  CONSTRAINT `acoes_log_ibfk_1`
    FOREIGN KEY (`usuario_id`)
    REFERENCES `ints_db`.`usuarios` (`id`),
  CONSTRAINT `acoes_log_ibfk_2`
    FOREIGN KEY (`produto_id`)
    REFERENCES `ints_db`.`produtos` (`id`))
ENGINE = InnoDB
AUTO_INCREMENT = 28
DEFAULT CHARACTER SET = latin1;


-- -----------------------------------------------------
-- Table `ints_db`.`alertas_locacao`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `ints_db`.`alertas_locacao` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `contrato_id` INT(11) NOT NULL,
  `tipo_alerta` ENUM('vencimento_proximo', 'pagamento_atrasado', 'renovacao', 'documento_vencido') NOT NULL,
  `mensagem` TEXT NOT NULL,
  `data_alerta` DATE NOT NULL,
  `visualizado` TINYINT(1) NULL DEFAULT 0,
  `data_visualizacao` TIMESTAMP NULL DEFAULT NULL,
  `usuario_visualizou` INT(11) NULL DEFAULT NULL,
  `data_criado` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  INDEX `idx_contrato` (`contrato_id` ASC) VISIBLE,
  INDEX `idx_tipo` (`tipo_alerta` ASC) VISIBLE,
  INDEX `idx_visualizado` (`visualizado` ASC) VISIBLE,
  INDEX `idx_data_alerta` (`data_alerta` ASC) VISIBLE,
  INDEX `usuario_visualizou` (`usuario_visualizou` ASC) VISIBLE,
  CONSTRAINT `alertas_locacao_ibfk_1`
    FOREIGN KEY (`contrato_id`)
    REFERENCES `ints_db`.`contratos_locacao` (`id`)
    ON DELETE CASCADE,
  CONSTRAINT `alertas_locacao_ibfk_2`
    FOREIGN KEY (`usuario_visualizou`)
    REFERENCES `ints_db`.`usuarios` (`id`))
ENGINE = InnoDB
DEFAULT CHARACTER SET = utf8mb4
COMMENT = 'Alertas e notificações sobre contratos de locação';


-- -----------------------------------------------------
-- Table `ints_db`.`arquivos`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `ints_db`.`arquivos` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `produto_id` INT(11) NOT NULL,
  `tipo` ENUM('imagem', 'manual', 'nota_fiscal', 'outro') NOT NULL,
  `caminho` VARCHAR(255) NOT NULL,
  `data_criado` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  INDEX `produto_id` (`produto_id` ASC) VISIBLE,
  CONSTRAINT `arquivos_ibfk_1`
    FOREIGN KEY (`produto_id`)
    REFERENCES `ints_db`.`produtos` (`id`))
ENGINE = InnoDB
DEFAULT CHARACTER SET = latin1;


-- -----------------------------------------------------
-- Table `ints_db`.`atributos_definicao`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `ints_db`.`atributos_definicao` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `nome` VARCHAR(100) NOT NULL,
  `tipo` VARCHAR(50) NOT NULL,
  PRIMARY KEY (`id`))
ENGINE = InnoDB
AUTO_INCREMENT = 13
DEFAULT CHARACTER SET = latin1;


-- -----------------------------------------------------
-- Table `ints_db`.`atributo_regra_condicional`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `ints_db`.`atributo_regra_condicional` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `atributo_gatilho_id` INT(11) NOT NULL,
  `valor_gatilho` VARCHAR(255) NOT NULL,
  `atributo_alvo_id` INT(11) NOT NULL,
  `acao` ENUM('bloquear', 'tornar_obrigatorio') NOT NULL,
  PRIMARY KEY (`id`),
  INDEX `atributo_gatilho_id` (`atributo_gatilho_id` ASC) VISIBLE,
  INDEX `atributo_alvo_id` (`atributo_alvo_id` ASC) VISIBLE,
  CONSTRAINT `atributo_regra_condicional_ibfk_1`
    FOREIGN KEY (`atributo_gatilho_id`)
    REFERENCES `ints_db`.`atributos_definicao` (`id`),
  CONSTRAINT `atributo_regra_condicional_ibfk_2`
    FOREIGN KEY (`atributo_alvo_id`)
    REFERENCES `ints_db`.`atributos_definicao` (`id`))
ENGINE = InnoDB
DEFAULT CHARACTER SET = latin1;


-- -----------------------------------------------------
-- Table `ints_db`.`atributos_opcoes`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `ints_db`.`atributos_opcoes` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `atributo_id` INT(11) NOT NULL,
  `valor` VARCHAR(255) NOT NULL,
  PRIMARY KEY (`id`),
  INDEX `atributo_id` (`atributo_id` ASC) VISIBLE,
  CONSTRAINT `atributos_opcoes_ibfk_1`
    FOREIGN KEY (`atributo_id`)
    REFERENCES `ints_db`.`atributos_definicao` (`id`))
ENGINE = InnoDB
AUTO_INCREMENT = 9
DEFAULT CHARACTER SET = latin1;


-- -----------------------------------------------------
-- Table `ints_db`.`atributos_valores_permitidos`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `ints_db`.`atributos_valores_permitidos` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `atributo_id` INT(11) NOT NULL,
  `valor_permitido` VARCHAR(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `uk_atributo_valor` (`atributo_id` ASC, `valor_permitido` ASC) VISIBLE,
  CONSTRAINT `atributos_valores_permitidos_ibfk_1`
    FOREIGN KEY (`atributo_id`)
    REFERENCES `ints_db`.`atributos_definicao` (`id`))
ENGINE = InnoDB
AUTO_INCREMENT = 3
DEFAULT CHARACTER SET = latin1;


-- -----------------------------------------------------
-- Table `ints_db`.`atributos_valor`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `ints_db`.`atributos_valor` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `produto_id` INT(11) NOT NULL,
  `atributo_id` INT(11) NOT NULL,
  `valor_texto` TEXT NULL DEFAULT NULL,
  `valor_numero` DECIMAL(12,2) NULL DEFAULT NULL,
  `valor_booleano` TINYINT(1) NULL DEFAULT NULL,
  `valor_data` DATE NULL DEFAULT NULL,
  `valor_permitido_id` INT(11) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `uk_produto_atributo_valor` (`produto_id` ASC, `atributo_id` ASC) VISIBLE,
  INDEX `atributo_id` (`atributo_id` ASC) VISIBLE,
  INDEX `valor_permitido_id` (`valor_permitido_id` ASC) VISIBLE,
  INDEX `idx_atributos_valor_produto_atributo` (`produto_id` ASC, `atributo_id` ASC) VISIBLE,
  INDEX `idx_atributos_valor_lookup` (`produto_id` ASC, `atributo_id` ASC) VISIBLE,
  CONSTRAINT `atributos_valor_ibfk_1`
    FOREIGN KEY (`produto_id`)
    REFERENCES `ints_db`.`produtos` (`id`),
  CONSTRAINT `atributos_valor_ibfk_2`
    FOREIGN KEY (`atributo_id`)
    REFERENCES `ints_db`.`atributos_definicao` (`id`),
  CONSTRAINT `atributos_valor_ibfk_3`
    FOREIGN KEY (`valor_permitido_id`)
    REFERENCES `ints_db`.`atributos_valores_permitidos` (`id`))
ENGINE = InnoDB
AUTO_INCREMENT = 28
DEFAULT CHARACTER SET = latin1;


-- -----------------------------------------------------
-- Table `ints_db`.`atributos_valor_historico`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `ints_db`.`atributos_valor_historico` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `atributo_valor_id` INT(11) NOT NULL,
  `valor_antigo` TEXT NULL DEFAULT NULL,
  `valor_novo` TEXT NULL DEFAULT NULL,
  `data_alterado` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `alterado_por` INT(11) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  INDEX `atributo_valor_id` (`atributo_valor_id` ASC) VISIBLE,
  INDEX `alterado_por` (`alterado_por` ASC) VISIBLE,
  CONSTRAINT `atributos_valor_historico_ibfk_1`
    FOREIGN KEY (`atributo_valor_id`)
    REFERENCES `ints_db`.`atributos_valor` (`id`),
  CONSTRAINT `atributos_valor_historico_ibfk_2`
    FOREIGN KEY (`alterado_por`)
    REFERENCES `ints_db`.`usuarios` (`id`))
ENGINE = InnoDB
DEFAULT CHARACTER SET = latin1;


-- -----------------------------------------------------
-- Table `ints_db`.`patrimonios`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `ints_db`.`patrimonios` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `produto_id` INT(11) NOT NULL,
  `numero_patrimonio` VARCHAR(100) NULL DEFAULT NULL,
  `numero_serie` VARCHAR(255) NULL DEFAULT NULL,
  `local_id` INT(11) NULL DEFAULT NULL,
  `status` ENUM('ativo', 'emprestado', 'manutencao', 'desativado') NOT NULL DEFAULT 'ativo',
  `data_aquisicao` DATE NULL DEFAULT NULL,
  `valor_aquisicao` DECIMAL(12,2) NULL DEFAULT NULL COMMENT 'Valor de aquisição do patrimônio',
  `vida_util_meses` INT(11) NULL DEFAULT NULL COMMENT 'Vida útil estimada em meses',
  `fornecedor` VARCHAR(200) NULL DEFAULT NULL COMMENT 'Fornecedor/Loja de onde foi adquirido',
  `nota_fiscal` VARCHAR(100) NULL DEFAULT NULL COMMENT 'Número da nota fiscal de aquisição',
  `garantia_meses` INT(11) NULL DEFAULT NULL COMMENT 'Período de garantia em meses',
  `data_fim_garantia` DATE NULL DEFAULT NULL COMMENT 'Data de término da garantia (calculado ou manual)',
  `observacoes` TEXT NULL DEFAULT NULL,
  `criado_por` INT(11) NULL DEFAULT NULL,
  `data_criado` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  UNIQUE INDEX `uk_patrimonio_num` (`numero_patrimonio` ASC) VISIBLE,
  INDEX `idx_patr_prod` (`produto_id` ASC) VISIBLE,
  INDEX `idx_patr_local` (`local_id` ASC) VISIBLE,
  INDEX `idx_patrimonios_status` (`status` ASC) VISIBLE,
  INDEX `idx_patrimonios_produto` (`produto_id` ASC) VISIBLE)
ENGINE = InnoDB
DEFAULT CHARACTER SET = latin1;


-- -----------------------------------------------------
-- Table `ints_db`.`locais`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `ints_db`.`locais` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `nome` VARCHAR(100) NOT NULL,
  `endereco` VARCHAR(300) NOT NULL,
  `local_pai_id` INT(11) NULL DEFAULT NULL,
  `tipo_local` ENUM('unidade', 'andar', 'sala', 'outro') NULL DEFAULT 'outro',
  `deletado` TINYINT(1) NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  INDEX `idx_locais_hierarquia` (`local_pai_id` ASC) VISIBLE,
  CONSTRAINT `locais_ibfk_1`
    FOREIGN KEY (`local_pai_id`)
    REFERENCES `ints_db`.`locais` (`id`))
ENGINE = InnoDB
AUTO_INCREMENT = 8
DEFAULT CHARACTER SET = latin1;


-- -----------------------------------------------------
-- Table `ints_db`.`baixas`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `ints_db`.`baixas` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `produto_id` INT(11) NOT NULL,
  `patrimonio_id` INT(11) NULL DEFAULT NULL,
  `quantidade` DECIMAL(12,4) NOT NULL DEFAULT 1.0000,
  `local_id` INT(11) NULL DEFAULT NULL,
  `motivo` ENUM('perda', 'dano', 'obsolescencia', 'devolucao_locacao', 'descarte', 'doacao', 'roubo', 'outro') NOT NULL,
  `descricao` TEXT NOT NULL,
  `data_baixa` DATE NOT NULL,
  `valor_contabil` DECIMAL(12,2) NULL DEFAULT NULL,
  `responsavel_id` INT(11) NULL DEFAULT NULL,
  `aprovador_id` INT(11) NULL DEFAULT NULL,
  `documentos_anexos` TEXT NULL DEFAULT NULL,
  `criado_por` INT(11) NOT NULL,
  `data_criado` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `status` ENUM('pendente', 'aprovada', 'rejeitada', 'cancelada') NOT NULL DEFAULT 'pendente',
  PRIMARY KEY (`id`),
  INDEX `idx_baixas_produto` (`produto_id` ASC) VISIBLE,
  INDEX `idx_baixas_patrimonio` (`patrimonio_id` ASC) VISIBLE,
  INDEX `idx_baixas_data` (`data_baixa` ASC) VISIBLE,
  INDEX `idx_baixas_status` (`status` ASC) VISIBLE,
  INDEX `baixas_ibfk_3` (`local_id` ASC) VISIBLE,
  INDEX `baixas_ibfk_4` (`criado_por` ASC) VISIBLE,
  INDEX `baixas_ibfk_5` (`responsavel_id` ASC) VISIBLE,
  INDEX `baixas_ibfk_6` (`aprovador_id` ASC) VISIBLE,
  CONSTRAINT `baixas_ibfk_1`
    FOREIGN KEY (`produto_id`)
    REFERENCES `ints_db`.`produtos` (`id`),
  CONSTRAINT `baixas_ibfk_2`
    FOREIGN KEY (`patrimonio_id`)
    REFERENCES `ints_db`.`patrimonios` (`id`),
  CONSTRAINT `baixas_ibfk_3`
    FOREIGN KEY (`local_id`)
    REFERENCES `ints_db`.`locais` (`id`),
  CONSTRAINT `baixas_ibfk_4`
    FOREIGN KEY (`criado_por`)
    REFERENCES `ints_db`.`usuarios` (`id`),
  CONSTRAINT `baixas_ibfk_5`
    FOREIGN KEY (`responsavel_id`)
    REFERENCES `ints_db`.`usuarios` (`id`),
  CONSTRAINT `baixas_ibfk_6`
    FOREIGN KEY (`aprovador_id`)
    REFERENCES `ints_db`.`usuarios` (`id`))
ENGINE = InnoDB
AUTO_INCREMENT = 2
DEFAULT CHARACTER SET = utf8mb4;


-- -----------------------------------------------------
-- Table `ints_db`.`baixas_historico`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `ints_db`.`baixas_historico` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `produto_id` INT(11) NOT NULL,
  `quantidade_baixa` DECIMAL(12,4) NOT NULL,
  `local_id` INT(11) NULL DEFAULT NULL,
  `motivo` ENUM('perda', 'quebra', 'obsolescencia', 'doacao', 'venda', 'roubo', 'outro') NOT NULL,
  `descricao_motivo` TEXT NULL DEFAULT NULL,
  `usuario_id` INT(11) NOT NULL,
  `data_baixa` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `documento_comprobatorio` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Caminho para documento que comprova a baixa',
  PRIMARY KEY (`id`),
  INDEX `idx_produto` (`produto_id` ASC) VISIBLE,
  INDEX `idx_usuario` (`usuario_id` ASC) VISIBLE,
  INDEX `idx_data` (`data_baixa` ASC) VISIBLE,
  INDEX `local_id` (`local_id` ASC) VISIBLE,
  CONSTRAINT `baixas_historico_ibfk_1`
    FOREIGN KEY (`produto_id`)
    REFERENCES `ints_db`.`produtos` (`id`),
  CONSTRAINT `baixas_historico_ibfk_2`
    FOREIGN KEY (`usuario_id`)
    REFERENCES `ints_db`.`usuarios` (`id`),
  CONSTRAINT `baixas_historico_ibfk_3`
    FOREIGN KEY (`local_id`)
    REFERENCES `ints_db`.`locais` (`id`))
ENGINE = InnoDB
DEFAULT CHARACTER SET = utf8mb4
COMMENT = 'Histórico de baixas de produtos';


-- -----------------------------------------------------
-- Table `ints_db`.`categoria_atributo`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `ints_db`.`categoria_atributo` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `categoria_id` INT(11) NOT NULL,
  `atributo_id` INT(11) NOT NULL,
  `obrigatorio` TINYINT(1) NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  INDEX `atributo_id` (`atributo_id` ASC) VISIBLE,
  INDEX `idx_categoria_atributo_lookup` (`categoria_id` ASC, `atributo_id` ASC) VISIBLE,
  CONSTRAINT `categoria_atributo_ibfk_1`
    FOREIGN KEY (`categoria_id`)
    REFERENCES `ints_db`.`categorias` (`id`),
  CONSTRAINT `categoria_atributo_ibfk_2`
    FOREIGN KEY (`atributo_id`)
    REFERENCES `ints_db`.`atributos_definicao` (`id`))
ENGINE = InnoDB
AUTO_INCREMENT = 13
DEFAULT CHARACTER SET = latin1;


-- -----------------------------------------------------
-- Table `ints_db`.`categoria_atributo_opcao`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `ints_db`.`categoria_atributo_opcao` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `categoria_id` INT(11) NOT NULL,
  `atributo_opcao_id` INT(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `uk_cat_attr_op` (`categoria_id` ASC, `atributo_opcao_id` ASC) VISIBLE,
  INDEX `atributo_opcao_id` (`atributo_opcao_id` ASC) VISIBLE,
  CONSTRAINT `categoria_atributo_opcao_ibfk_1`
    FOREIGN KEY (`categoria_id`)
    REFERENCES `ints_db`.`categorias` (`id`),
  CONSTRAINT `categoria_atributo_opcao_ibfk_2`
    FOREIGN KEY (`atributo_opcao_id`)
    REFERENCES `ints_db`.`atributos_opcoes` (`id`))
ENGINE = InnoDB
AUTO_INCREMENT = 6
DEFAULT CHARACTER SET = latin1;


-- -----------------------------------------------------
-- Table `ints_db`.`estoques`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `ints_db`.`estoques` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `produto_id` INT(11) NOT NULL,
  `local_id` INT(11) NULL DEFAULT NULL,
  `quantidade` INT(11) NOT NULL DEFAULT 0,
  `data_atualizado` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  UNIQUE INDEX `uk_estoque_produto_local` (`produto_id` ASC, `local_id` ASC) VISIBLE,
  UNIQUE INDEX `uk_estoques_prod_local` (`produto_id` ASC, `local_id` ASC) VISIBLE,
  INDEX `idx_estoques_local` (`local_id` ASC) VISIBLE,
  INDEX `idx_estoques_lookup` (`produto_id` ASC, `local_id` ASC) VISIBLE,
  CONSTRAINT `estoques_ibfk_1`
    FOREIGN KEY (`produto_id`)
    REFERENCES `ints_db`.`produtos` (`id`),
  CONSTRAINT `estoques_ibfk_2`
    FOREIGN KEY (`local_id`)
    REFERENCES `ints_db`.`locais` (`id`))
ENGINE = InnoDB
AUTO_INCREMENT = 11
DEFAULT CHARACTER SET = latin1;


-- -----------------------------------------------------
-- Table `ints_db`.`movimentacoes`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `ints_db`.`movimentacoes` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `movimentacao_pai_id` INT(11) NULL DEFAULT NULL,
  `produto_id` INT(11) NOT NULL,
  `local_origem_id` INT(11) NOT NULL,
  `local_destino_id` INT(11) NOT NULL,
  `quantidade` INT(11) NOT NULL DEFAULT 1,
  `usuario_id` INT(11) NOT NULL,
  `usuario_aprovacao_id` INT(11) NULL DEFAULT NULL,
  `usuario_recebimento_id` INT(11) NULL DEFAULT NULL,
  `data_movimentacao` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `status` ENUM('pendente', 'em_transito', 'finalizado', 'cancelado') NOT NULL DEFAULT 'pendente',
  `tipo_movimentacao` ENUM('TRANSFERENCIA', 'COMPONENTE', 'AJUSTE') NULL DEFAULT 'TRANSFERENCIA',
  `data_atualizacao` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  INDEX `local_origem_id` (`local_origem_id` ASC) VISIBLE,
  INDEX `local_destino_id` (`local_destino_id` ASC) VISIBLE,
  INDEX `usuario_id` (`usuario_id` ASC) VISIBLE,
  INDEX `idx_movimentacoes_produto` (`produto_id` ASC) VISIBLE,
  INDEX `idx_movimentacoes_data` (`data_movimentacao` ASC) VISIBLE,
  INDEX `idx_mov_pai` (`movimentacao_pai_id` ASC) VISIBLE,
  INDEX `idx_mov_usuario_recebimento` (`usuario_recebimento_id` ASC) VISIBLE,
  CONSTRAINT `fk_movimentacao_pai`
    FOREIGN KEY (`movimentacao_pai_id`)
    REFERENCES `ints_db`.`movimentacoes` (`id`),
  CONSTRAINT `movimentacoes_ibfk_1`
    FOREIGN KEY (`produto_id`)
    REFERENCES `ints_db`.`produtos` (`id`),
  CONSTRAINT `movimentacoes_ibfk_2`
    FOREIGN KEY (`local_origem_id`)
    REFERENCES `ints_db`.`locais` (`id`),
  CONSTRAINT `movimentacoes_ibfk_3`
    FOREIGN KEY (`local_destino_id`)
    REFERENCES `ints_db`.`locais` (`id`),
  CONSTRAINT `movimentacoes_ibfk_4`
    FOREIGN KEY (`usuario_id`)
    REFERENCES `ints_db`.`usuarios` (`id`))
ENGINE = InnoDB
AUTO_INCREMENT = 4
DEFAULT CHARACTER SET = latin1;


-- -----------------------------------------------------
-- Table `ints_db`.`pagamentos_locacao`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `ints_db`.`pagamentos_locacao` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `contrato_id` INT(11) NOT NULL,
  `data_vencimento` DATE NOT NULL,
  `valor` DECIMAL(12,2) NOT NULL,
  `data_pagamento` DATE NULL DEFAULT NULL,
  `valor_pago` DECIMAL(12,2) NULL DEFAULT NULL,
  `status` ENUM('pendente', 'pago', 'atrasado', 'cancelado') NULL DEFAULT 'pendente',
  `forma_pagamento` VARCHAR(50) NULL DEFAULT NULL COMMENT 'Boleto, PIX, Transferência, etc',
  `comprovante` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Caminho do arquivo de comprovante',
  `observacoes` TEXT NULL DEFAULT NULL,
  `usuario_id` INT(11) NULL DEFAULT NULL,
  `data_criado` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `data_atualizado` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  INDEX `idx_contrato` (`contrato_id` ASC) VISIBLE,
  INDEX `idx_status` (`status` ASC) VISIBLE,
  INDEX `idx_vencimento` (`data_vencimento` ASC) VISIBLE,
  INDEX `usuario_id` (`usuario_id` ASC) VISIBLE,
  CONSTRAINT `pagamentos_locacao_ibfk_1`
    FOREIGN KEY (`contrato_id`)
    REFERENCES `ints_db`.`contratos_locacao` (`id`)
    ON DELETE CASCADE,
  CONSTRAINT `pagamentos_locacao_ibfk_2`
    FOREIGN KEY (`usuario_id`)
    REFERENCES `ints_db`.`usuarios` (`id`))
ENGINE = InnoDB
DEFAULT CHARACTER SET = utf8mb4
COMMENT = 'Controle de pagamentos mensais de locação';


-- -----------------------------------------------------
-- Table `ints_db`.`patrimonio_manutencoes`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `ints_db`.`patrimonio_manutencoes` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `patrimonio_id` INT(11) NOT NULL,
  `tipo_manutencao` ENUM('preventiva', 'corretiva', 'revisao', 'limpeza', 'outro') NOT NULL,
  `descricao` TEXT NOT NULL,
  `data_manutencao` DATE NOT NULL,
  `custo` DECIMAL(12,2) NULL DEFAULT NULL,
  `responsavel` VARCHAR(150) NULL DEFAULT NULL COMMENT 'Empresa ou pessoa que executou',
  `observacoes` TEXT NULL DEFAULT NULL,
  `usuario_id` INT(11) NULL DEFAULT NULL,
  `data_criado` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  INDEX `idx_patrimonio` (`patrimonio_id` ASC) VISIBLE,
  INDEX `idx_data` (`data_manutencao` ASC) VISIBLE,
  INDEX `idx_tipo` (`tipo_manutencao` ASC) VISIBLE,
  INDEX `usuario_id` (`usuario_id` ASC) VISIBLE,
  CONSTRAINT `patrimonio_manutencoes_ibfk_1`
    FOREIGN KEY (`patrimonio_id`)
    REFERENCES `ints_db`.`patrimonios` (`id`)
    ON DELETE CASCADE,
  CONSTRAINT `patrimonio_manutencoes_ibfk_2`
    FOREIGN KEY (`usuario_id`)
    REFERENCES `ints_db`.`usuarios` (`id`))
ENGINE = InnoDB
DEFAULT CHARACTER SET = utf8mb4
COMMENT = 'Histórico de manutenções dos patrimônios';


-- -----------------------------------------------------
-- Table `ints_db`.`produto_relacionamento`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `ints_db`.`produto_relacionamento` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `produto_principal_id` INT(11) NOT NULL,
  `subproduto_id` INT(11) NOT NULL,
  `quantidade` INT(11) NOT NULL DEFAULT 0,
  `tipo_relacao` ENUM('componente', 'kit', 'acessorio') NOT NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_relacionamento_principal` (`produto_principal_id` ASC) VISIBLE,
  INDEX `idx_relacionamento_subproduto` (`subproduto_id` ASC) VISIBLE,
  CONSTRAINT `produto_relacionamento_ibfk_1`
    FOREIGN KEY (`produto_principal_id`)
    REFERENCES `ints_db`.`produtos` (`id`),
  CONSTRAINT `produto_relacionamento_ibfk_2`
    FOREIGN KEY (`subproduto_id`)
    REFERENCES `ints_db`.`produtos` (`id`))
ENGINE = InnoDB
DEFAULT CHARACTER SET = latin1;


-- -----------------------------------------------------
-- Table `ints_db`.`produtos_status_historico`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `ints_db`.`produtos_status_historico` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `produto_id` INT(11) NOT NULL,
  `status_anterior` ENUM('ativo', 'baixa_parcial', 'baixa_total', 'inativo') NULL DEFAULT NULL,
  `status_novo` ENUM('ativo', 'baixa_parcial', 'baixa_total', 'inativo') NOT NULL,
  `usuario_id` INT(11) NOT NULL,
  `data_alteracao` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `observacoes` TEXT NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_produto` (`produto_id` ASC) VISIBLE,
  INDEX `idx_data` (`data_alteracao` ASC) VISIBLE,
  INDEX `usuario_id` (`usuario_id` ASC) VISIBLE,
  CONSTRAINT `produtos_status_historico_ibfk_1`
    FOREIGN KEY (`produto_id`)
    REFERENCES `ints_db`.`produtos` (`id`),
  CONSTRAINT `produtos_status_historico_ibfk_2`
    FOREIGN KEY (`usuario_id`)
    REFERENCES `ints_db`.`usuarios` (`id`))
ENGINE = InnoDB
DEFAULT CHARACTER SET = utf8mb4
COMMENT = 'Histórico de alterações de status dos produtos';


-- -----------------------------------------------------
-- Table `ints_db`.`reservas`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `ints_db`.`reservas` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `produto_id` INT(11) NOT NULL,
  `local_id` INT(11) NULL DEFAULT NULL,
  `quantidade` DECIMAL(12,4) NOT NULL,
  `referencia_tipo` VARCHAR(50) NULL DEFAULT NULL,
  `referencia_id` INT(11) NULL DEFAULT NULL,
  `criado_por` INT(11) NULL DEFAULT NULL,
  `data_criado` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `expires_at` TIMESTAMP NULL DEFAULT NULL,
  `referencia_batch` VARCHAR(50) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `uk_reserva_prod_local_ref` (`produto_id` ASC, `local_id` ASC, `referencia_tipo` ASC, `referencia_id` ASC) VISIBLE,
  INDEX `idx_produto` (`produto_id` ASC) VISIBLE,
  INDEX `idx_local` (`local_id` ASC) VISIBLE,
  INDEX `idx_reservas_expires` (`expires_at` ASC) VISIBLE)
ENGINE = InnoDB
DEFAULT CHARACTER SET = latin1;

USE `ints_db` ;

-- -----------------------------------------------------
-- Placeholder table for view `ints_db`.`vw_patrimonio_detalhado`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `ints_db`.`vw_patrimonio_detalhado` (`patrimonio_id` INT, `produto_nome` INT, `categoria` INT, `numero_patrimonio` INT, `status` INT, `local_nome` INT, `marca` INT, `modelo` INT, `voltagem` INT);

-- -----------------------------------------------------
-- Placeholder table for view `ints_db`.`vw_patrimonios_completo`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `ints_db`.`vw_patrimonios_completo` (`patrimonio_id` INT, `numero_patrimonio` INT, `numero_serie` INT, `status` INT, `data_aquisicao` INT, `valor_aquisicao` INT, `vida_util_meses` INT, `fornecedor` INT, `nota_fiscal` INT, `garantia_meses` INT, `data_fim_garantia` INT, `observacoes` INT, `produto_id` INT, `produto_nome` INT, `categoria_nome` INT, `local_id` INT, `local_nome` INT, `situacao_garantia` INT, `total_manutencoes` INT, `ultima_manutencao` INT);

-- -----------------------------------------------------
-- Placeholder table for view `ints_db`.`vw_produtos_locados`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `ints_db`.`vw_produtos_locados` (`produto_id` INT, `produto_nome` INT, `numero_patrimonio` INT, `status_produto` INT, `contrato_id` INT, `numero_contrato` INT, `contrato_status` INT, `data_inicio` INT, `data_fim` INT, `valor_mensal` INT, `locador_id` INT, `locador_nome` INT, `cnpj` INT, `locador_telefone` INT, `locador_email` INT, `dias_para_vencimento` INT, `situacao_contrato` INT);

-- -----------------------------------------------------
-- function gerar_numero_patrimonio
-- -----------------------------------------------------

DELIMITER $$
USE `ints_db`$$
CREATE DEFINER=`root`@`localhost` FUNCTION `gerar_numero_patrimonio`(p_unidade_id INT, p_categoria_id INT, p_produto_id INT) RETURNS varchar(50) CHARSET utf8mb4 COLLATE utf8mb4_general_ci
    DETERMINISTIC
BEGIN
    DECLARE novo_codigo VARCHAR(50);
    DECLARE sequencial INT;
    
    -- Se não houver unidade definida, usa 0
    SET p_unidade_id = IFNULL(p_unidade_id, 0);
    
    -- Pega o próximo número baseado no ID do produto (ou você pode criar uma tabela de sequenciais se preferir)
    -- Aqui estou usando o próprio ID do produto com padding de zeros para simplificar e garantir unicidade
    SET sequencial = p_produto_id;
    
    -- Formato: UUU-CCC-PPPPPP (Unidade-Categoria-Produto)
    SET novo_codigo = CONCAT(
        LPAD(p_unidade_id, 3, '0'), '-', 
        LPAD(p_categoria_id, 3, '0'), '-', 
        LPAD(sequencial, 6, '0')
    );
    
    RETURN novo_codigo;
END$$

DELIMITER ;

-- -----------------------------------------------------
-- procedure sp_gerar_alertas_locacao
-- -----------------------------------------------------

DELIMITER $$
USE `ints_db`$$
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_gerar_alertas_locacao`()
BEGIN
    -- Limpar alertas antigos não visualizados (mais de 90 dias)
    DELETE FROM alertas_locacao 
    WHERE visualizado = 0 AND data_criado < DATE_SUB(CURDATE(), INTERVAL 90 DAY);
    
    -- Alertar contratos vencendo em 30 dias
    INSERT INTO alertas_locacao (contrato_id, tipo_alerta, mensagem, data_alerta)
    SELECT 
        c.id,
        'vencimento_proximo',
        CONCAT('Contrato ', c.numero_contrato, ' vence em ', DATEDIFF(c.data_fim, CURDATE()), ' dias'),
        CURDATE()
    FROM contratos_locacao c
    WHERE c.status = 'ativo'
      AND c.data_fim IS NOT NULL
      AND DATEDIFF(c.data_fim, CURDATE()) <= 30
      AND DATEDIFF(c.data_fim, CURDATE()) > 0
      AND NOT EXISTS (
          SELECT 1 FROM alertas_locacao a 
          WHERE a.contrato_id = c.id 
            AND a.tipo_alerta = 'vencimento_proximo'
            AND a.data_alerta >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
      );
    
    -- Alertar contratos vencidos
    UPDATE contratos_locacao 
    SET status = 'vencido'
    WHERE status = 'ativo' 
      AND data_fim IS NOT NULL 
      AND data_fim < CURDATE();
    
END$$

DELIMITER ;

-- -----------------------------------------------------
-- procedure sp_registrar_movimentacao
-- -----------------------------------------------------

DELIMITER $$
USE `ints_db`$$
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_registrar_movimentacao`(
    IN p_produto_id INT,
    IN p_origem_id INT,
    IN p_destino_id INT,
    IN p_qtd INT,
    IN p_usuario_id INT
)
BEGIN
    START TRANSACTION;
        -- 1. Tira da origem
        UPDATE estoques SET quantidade = quantidade - p_qtd 
        WHERE produto_id = p_produto_id AND local_id = p_origem_id;
        
        -- 2. Coloca no destino (ou cria se não existir)
        INSERT INTO estoques (produto_id, local_id, quantidade)
        VALUES (p_produto_id, p_destino_id, p_qtd)
        ON DUPLICATE KEY UPDATE quantidade = quantidade + p_qtd;
        
        -- 3. Registra o Log de Movimentação
        INSERT INTO movimentacoes (produto_id, local_origem_id, local_destino_id, quantidade, usuario_id, status)
        VALUES (p_produto_id, p_origem_id, p_destino_id, p_qtd, p_usuario_id, 'finalizado');
    COMMIT;
END$$

DELIMITER ;

-- -----------------------------------------------------
-- View `ints_db`.`vw_patrimonio_detalhado`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `ints_db`.`vw_patrimonio_detalhado`;
USE `ints_db`;
CREATE  OR REPLACE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `ints_db`.`vw_patrimonio_detalhado` AS select `p`.`id` AS `patrimonio_id`,`prod`.`nome` AS `produto_nome`,`cat`.`nome` AS `categoria`,`p`.`numero_patrimonio` AS `numero_patrimonio`,`p`.`status` AS `status`,`l`.`nome` AS `local_nome`,max(case when `ad`.`nome` = 'Marca' then `av`.`valor_texto` end) AS `marca`,max(case when `ad`.`nome` = 'Modelo' then `av`.`valor_texto` end) AS `modelo`,max(case when `ad`.`nome` = 'Voltagem' then `av`.`valor_texto` end) AS `voltagem` from (((((`ints_db`.`patrimonios` `p` join `ints_db`.`produtos` `prod` on(`p`.`produto_id` = `prod`.`id`)) join `ints_db`.`categorias` `cat` on(`prod`.`categoria_id` = `cat`.`id`)) join `ints_db`.`locais` `l` on(`p`.`local_id` = `l`.`id`)) left join `ints_db`.`atributos_valor` `av` on(`prod`.`id` = `av`.`produto_id`)) left join `ints_db`.`atributos_definicao` `ad` on(`av`.`atributo_id` = `ad`.`id`)) group by `p`.`id`;

-- -----------------------------------------------------
-- View `ints_db`.`vw_patrimonios_completo`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `ints_db`.`vw_patrimonios_completo`;
USE `ints_db`;
CREATE  OR REPLACE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `ints_db`.`vw_patrimonios_completo` AS select `pt`.`id` AS `patrimonio_id`,`pt`.`numero_patrimonio` AS `numero_patrimonio`,`pt`.`numero_serie` AS `numero_serie`,`pt`.`status` AS `status`,`pt`.`data_aquisicao` AS `data_aquisicao`,`pt`.`valor_aquisicao` AS `valor_aquisicao`,`pt`.`vida_util_meses` AS `vida_util_meses`,`pt`.`fornecedor` AS `fornecedor`,`pt`.`nota_fiscal` AS `nota_fiscal`,`pt`.`garantia_meses` AS `garantia_meses`,`pt`.`data_fim_garantia` AS `data_fim_garantia`,`pt`.`observacoes` AS `observacoes`,`p`.`id` AS `produto_id`,`p`.`nome` AS `produto_nome`,`c`.`nome` AS `categoria_nome`,`l`.`id` AS `local_id`,`l`.`nome` AS `local_nome`,case when `pt`.`data_fim_garantia` is not null and `pt`.`data_fim_garantia` < curdate() then 'GARANTIA_VENCIDA' when `pt`.`data_fim_garantia` is not null and to_days(`pt`.`data_fim_garantia`) - to_days(curdate()) <= 60 then 'GARANTIA_VENCENDO' when `pt`.`data_fim_garantia` is not null then 'EM_GARANTIA' else 'SEM_GARANTIA' end AS `situacao_garantia`,(select count(0) from `ints_db`.`patrimonio_manutencoes` `pm` where `pm`.`patrimonio_id` = `pt`.`id`) AS `total_manutencoes`,(select max(`pm2`.`data_manutencao`) from `ints_db`.`patrimonio_manutencoes` `pm2` where `pm2`.`patrimonio_id` = `pt`.`id`) AS `ultima_manutencao` from (((`ints_db`.`patrimonios` `pt` join `ints_db`.`produtos` `p` on(`pt`.`produto_id` = `p`.`id`)) left join `ints_db`.`categorias` `c` on(`p`.`categoria_id` = `c`.`id`)) left join `ints_db`.`locais` `l` on(`pt`.`local_id` = `l`.`id`));

-- -----------------------------------------------------
-- View `ints_db`.`vw_produtos_locados`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `ints_db`.`vw_produtos_locados`;
USE `ints_db`;
CREATE  OR REPLACE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `ints_db`.`vw_produtos_locados` AS select `p`.`id` AS `produto_id`,`p`.`nome` AS `produto_nome`,`p`.`numero_patrimonio` AS `numero_patrimonio`,`p`.`status_produto` AS `status_produto`,`c`.`id` AS `contrato_id`,`c`.`numero_contrato` AS `numero_contrato`,`c`.`status` AS `contrato_status`,`c`.`data_inicio` AS `data_inicio`,`c`.`data_fim` AS `data_fim`,`c`.`valor_mensal` AS `valor_mensal`,`l`.`id` AS `locador_id`,`l`.`nome` AS `locador_nome`,`l`.`cnpj` AS `cnpj`,`l`.`telefone` AS `locador_telefone`,`l`.`email` AS `locador_email`,to_days(`c`.`data_fim`) - to_days(curdate()) AS `dias_para_vencimento`,case when `c`.`data_fim` < curdate() then 'VENCIDO' when to_days(`c`.`data_fim`) - to_days(curdate()) <= 30 then 'VENCE_EM_BREVE' else 'VIGENTE' end AS `situacao_contrato` from ((`ints_db`.`produtos` `p` join `ints_db`.`contratos_locacao` `c` on(`p`.`contrato_locacao_id` = `c`.`id`)) join `ints_db`.`locadores` `l` on(`c`.`locador_id` = `l`.`id`)) where `p`.`tipo_posse` = 'locado' and `p`.`deletado` = 0;
USE `ints_db`;

DELIMITER $$
USE `ints_db`$$
CREATE
DEFINER=`root`@`localhost`
TRIGGER `ints_db`.`before_categoria_update`
BEFORE UPDATE ON `ints_db`.`categorias`
FOR EACH ROW
BEGIN
    DECLARE ciclo_detectado INT DEFAULT 0;
    DECLARE pai_atual INT;
    DECLARE profundidade INT DEFAULT 0;
    
    SET pai_atual = NEW.categoria_pai_id;
    
    WHILE pai_atual IS NOT NULL AND profundidade < 10 DO
        IF pai_atual = NEW.id THEN
            SET ciclo_detectado = 1;
            SET pai_atual = NULL;
        ELSE
            SELECT categoria_pai_id INTO pai_atual FROM categorias WHERE id = pai_atual;
            SET profundidade = profundidade + 1;
        END IF;
    END WHILE;
    
    IF ciclo_detectado = 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ciclo detectado na hierarquia de categorias';
    END IF;
END$$

USE `ints_db`$$
CREATE
DEFINER=`root`@`localhost`
TRIGGER `ints_db`.`trg_produtos_contrato_update`
AFTER UPDATE ON `ints_db`.`produtos`
FOR EACH ROW
BEGIN
    IF OLD.contrato_locacao_id != NEW.contrato_locacao_id 
       OR (OLD.contrato_locacao_id IS NULL AND NEW.contrato_locacao_id IS NOT NULL)
       OR (OLD.contrato_locacao_id IS NOT NULL AND NEW.contrato_locacao_id IS NULL) THEN
        
        -- Atualizar o contrato antigo se existir
        IF OLD.contrato_locacao_id IS NOT NULL THEN
            UPDATE contratos_locacao 
            SET data_atualizado = NOW() 
            WHERE id = OLD.contrato_locacao_id;
        END IF;
        
        -- Atualizar o contrato novo se existir
        IF NEW.contrato_locacao_id IS NOT NULL THEN
            UPDATE contratos_locacao 
            SET data_atualizado = NOW() 
            WHERE id = NEW.contrato_locacao_id;
        END IF;
    END IF;
END$$

USE `ints_db`$$
CREATE
DEFINER=`root`@`localhost`
TRIGGER `ints_db`.`tr_patrimonio_calc_garantia`
BEFORE INSERT ON `ints_db`.`patrimonios`
FOR EACH ROW
BEGIN
    IF NEW.data_aquisicao IS NOT NULL AND NEW.garantia_meses IS NOT NULL AND NEW.garantia_meses > 0 THEN
        SET NEW.data_fim_garantia = DATE_ADD(NEW.data_aquisicao, INTERVAL NEW.garantia_meses MONTH);
    END IF;
END$$

USE `ints_db`$$
CREATE
DEFINER=`root`@`localhost`
TRIGGER `ints_db`.`tr_patrimonio_update_garantia`
BEFORE UPDATE ON `ints_db`.`patrimonios`
FOR EACH ROW
BEGIN
    IF NEW.data_aquisicao IS NOT NULL AND NEW.garantia_meses IS NOT NULL AND NEW.garantia_meses > 0 THEN
        IF NEW.data_aquisicao != OLD.data_aquisicao OR NEW.garantia_meses != OLD.garantia_meses OR NEW.data_fim_garantia IS NULL THEN
            SET NEW.data_fim_garantia = DATE_ADD(NEW.data_aquisicao, INTERVAL NEW.garantia_meses MONTH);
        END IF;
    END IF;
END$$


DELIMITER ;

SET SQL_MODE=@OLD_SQL_MODE;
SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;
SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS;
