<?php
/**
 * Limpeza de reservas expiradas
 *
 * Uso:
 *  - Modo dry-run (padrão): php cleanup_reservas.php
 *  - Executar efetivamente: php cleanup_reservas.php --execute
 *  - Forçar revert de status de patrimônios reservados: --revert-patrimonios
 *
 * O script:
 *  - lista reservas com expires_at IS NOT NULL AND expires_at < NOW()
 *  - (opcional) reverte status de patrimônios referenciados para 'ativo' se --revert-patrimonios for passado
 *  - apaga as reservas expiradas (DELETE)
 *  - registra ação em log (stdout ou arquivo)
 *
 * Segurança / recomendações:
 *  - Fazer testes em staging primeiro (use --execute somente quando tiver verificado o dry-run)
 *  - Backup db antes de rodar em produção
 *
 * Instalação no cron (exemplo):
 *  - Rodar a cada 5 minutos:
 *    */5 * * * * /usr/bin/php /caminho/para/repo/ints/cron/cleanup_reservas.php --execute >> /var/log/ints/cleanup_reservas.log 2>&1
 *
 */

$ROOT = dirname(__DIR__, 1); // ajusta se necessário
// Ajuste o caminho para apontar ao seu loader / config que expõe $conn (mysqli)
require_once $ROOT . '/../config/_protecao.php';

$argv_flags = isset($argv) ? $argv : [];
$execute = in_array('--execute', $argv_flags);
$revert_patrimonios = in_array('--revert-patrimonios', $argv_flags);
$logFile = __DIR__ . '/cleanup_reservas.log';

// simple logger
function logmsg($msg) {
    global $logFile;
    $line = "[" . date('Y-m-d H:i:s') . "] " . $msg . PHP_EOL;
    // echo to stdout
    echo $line;
    // append to logfile
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

// verify $conn exists
if (!isset($conn) || !($conn instanceof mysqli)) {
    logmsg("ERRO: conexão com DB (\$conn) não encontrada. Ajuste require_once para seu projeto.");
    exit(1);
}
$conn->set_charset('utf8mb4');

try {
    // Count expired
    $sqlCount = "SELECT COUNT(*) AS c FROM reservas WHERE expires_at IS NOT NULL AND expires_at < NOW()";
    $res = $conn->query($sqlCount);
    $row = $res->fetch_assoc();
    $expiredCount = intval($row['c'] ?? 0);
    logmsg("Reservas expiradas encontradas: {$expiredCount}");

    if ($expiredCount === 0) {
        logmsg("Nada a fazer. Saindo.");
        exit(0);
    }

    // Show sample rows (dry-run info)
    $sampleSql = "SELECT id, produto_id, local_id, quantidade, referencia_tipo, referencia_id, referencia_batch, criado_por, data_criado, expires_at FROM reservas WHERE expires_at IS NOT NULL AND expires_at < NOW() ORDER BY expires_at ASC LIMIT 50";
    $res2 = $conn->query($sampleSql);
    logmsg("Amostra (até 50) de reservas expiradas:");
    while ($r = $res2->fetch_assoc()) {
        $line = json_encode($r, JSON_UNESCAPED_UNICODE);
        logmsg("  " . $line);
    }

    if (!$execute) {
        logmsg("Modo dry-run (não será feita remoção). Para executar use --execute");
        exit(0);
    }

    // Begin transaction to delete safely
    $conn->begin_transaction();

    // If revert_patrimonios option set, collect patrimonios affected
    $patr_ids = [];
    if ($revert_patrimonios) {
        $q = "SELECT referencia_id FROM reservas WHERE referencia_tipo = 'patrimonio' AND expires_at IS NOT NULL AND expires_at < NOW()";
        $rset = $conn->query($q);
        while ($rr = $rset->fetch_assoc()) {
            $patr_ids[] = intval($rr['referencia_id']);
        }
        $patr_ids = array_values(array_unique($patr_ids));
        logmsg("Patrimônios a considerar para revert status: " . count($patr_ids));
    }

    // Delete expired reservations, returning count
    $delStmt = $conn->prepare("DELETE FROM reservas WHERE expires_at IS NOT NULL AND expires_at < NOW()");
    if ($delStmt === false) throw new Exception("Erro prepare delete reservas: " . $conn->error);
    $delStmt->execute();
    $deleted = $delStmt->affected_rows;
    $delStmt->close();

    logmsg("Reservas expiradas removidas: {$deleted}");

    // If requested, revert patrimônios status to 'ativo' when applicable
    if ($revert_patrimonios && !empty($patr_ids)) {
        // Update only those that are not 'ativo' — avoid unnecessary writes
        $idsPlaceholders = implode(',', array_fill(0, count($patr_ids), '?'));
        $types = str_repeat('i', count($patr_ids));
        // Prepare statement
        $sqlUpd = "UPDATE patrimonios SET status = 'ativo' WHERE id IN ($idsPlaceholders) AND status <> 'ativo'";
        $stmtUpd = $conn->prepare($sqlUpd);
        if ($stmtUpd === false) throw new Exception("Erro prepare update patrimonios: " . $conn->error);
        // bind params dynamically
        $stmtUpd->bind_param($types, ...$patr_ids);
        $stmtUpd->execute();
        $affectedPatrs = $stmtUpd->affected_rows;
        $stmtUpd->close();
        logmsg("Patrimônios com status revertido para 'ativo': {$affectedPatrs}");
    }

    $conn->commit();
    logmsg("Limpeza de reservas expiradas executada com sucesso.");

    exit(0);

} catch (Exception $e) {
    if ($conn->in_transaction) $conn->rollback();
    logmsg("ERRO durante execução: " . $e->getMessage());
    exit(1);
}