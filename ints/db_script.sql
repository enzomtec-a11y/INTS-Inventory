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
AUTO_INCREMENT = 8
DEFAULT CHARACTER SET = latin1;


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
  `locador_nome` VARCHAR(255) NULL DEFAULT NULL,
  `data_criado` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `data_atualizado` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  `deletado` TINYINT(1) NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `numero_patrimonio` (`numero_patrimonio` ASC) VISIBLE,
  INDEX `idx_produtos_categoria` (`categoria_id` ASC) VISIBLE,
  INDEX `idx_numero_patrimonio` (`numero_patrimonio` ASC) VISIBLE,
  INDEX `idx_local_inicial` (`local_id_inicial` ASC) VISIBLE,
  CONSTRAINT `produtos_ibfk_1`
    FOREIGN KEY (`categoria_id`)
    REFERENCES `ints_db`.`categorias` (`id`))
ENGINE = InnoDB
AUTO_INCREMENT = 18
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
AUTO_INCREMENT = 50
DEFAULT CHARACTER SET = latin1;


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
AUTO_INCREMENT = 12
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
AUTO_INCREMENT = 4
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
AUTO_INCREMENT = 4
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
AUTO_INCREMENT = 98
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
AUTO_INCREMENT = 14
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
AUTO_INCREMENT = 7
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
AUTO_INCREMENT = 17
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
AUTO_INCREMENT = 7
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
  `observacoes` TEXT NULL DEFAULT NULL,
  `criado_por` INT(11) NULL DEFAULT NULL,
  `data_criado` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  UNIQUE INDEX `uk_patrimonio_num` (`numero_patrimonio` ASC) VISIBLE,
  INDEX `idx_patr_prod` (`produto_id` ASC) VISIBLE,
  INDEX `idx_patr_local` (`local_id` ASC) VISIBLE)
ENGINE = InnoDB
DEFAULT CHARACTER SET = latin1;


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
-- function gerar_numero_patrimonio
-- -----------------------------------------------------

DELIMITER $$
USE `ints_db`$$
CREATE DEFINER=`root`@`localhost` FUNCTION `gerar_numero_patrimonio`(p_unidade_id INT,
    p_categoria_id INT,
    p_produto_id INT
) RETURNS varchar(100) CHARSET utf8mb4 COLLATE utf8mb4_general_ci
    DETERMINISTIC
BEGIN
    DECLARE num_patrimonio VARCHAR(100);
    
    -- Formato: UNIDADE-CATEGORIA-PRODUTO
    -- Pads com zeros à esquerda para manter tamanho consistente
    SET num_patrimonio = CONCAT(
        LPAD(IFNULL(p_unidade_id, 0), 3, '0'), '-',
        LPAD(p_categoria_id, 3, '0'), '-',
        LPAD(p_produto_id, 6, '0')
    );
    
    RETURN num_patrimonio;
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
TRIGGER `ints_db`.`before_produto_insert_patrimonio`
BEFORE INSERT ON `ints_db`.`produtos`
FOR EACH ROW
BEGIN
    -- Gera o número de patrimônio baseado no próximo ID (AUTO_INCREMENT)
    -- Nota: NEW.id ainda não está disponível no BEFORE INSERT,
    -- então usaremos AFTER INSERT para atualizar
    SET NEW.numero_patrimonio = NULL;
END$$


DELIMITER ;

SET SQL_MODE=@OLD_SQL_MODE;
SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;
SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS;
