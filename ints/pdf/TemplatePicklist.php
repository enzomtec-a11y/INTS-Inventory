<?php
/**
 * TemplatePicklist
 * Gera o HTML do picklist (estrutura e estilo) usado pelo picklist.php para gerar preview ou PDF via TCPDF.
 *
 * Uso:
 *   require_once __DIR__ . '/TemplatePicklist.php';
 *   $html = TemplatePicklist::render($items, $meta, $refNote, ['show_qr'=>true, 'company'=>'Minha Empresa']);
 *
 * $items: array of ['produto_id','nome','local_id','local_nome','quantidade']
 * $meta: ['total_items'=>int,'total_quantity'=>float]
 * $refNote: string (referência / batch info)
 * $options: associative array, keys:
 *    - show_qr (bool) -> inclui QR com referencia_batch se disponível
 *    - company (string) -> nome da empresa para cabeçalho
 */
class TemplatePicklist
{
    public static function render(array $items, array $meta = [], $refNote = '', array $options = [])
    {
        $company = htmlspecialchars($options['company'] ?? 'Empresa');
        $date = date('Y-m-d H:i');
        $show_qr = !empty($options['show_qr']);
        $refNoteEsc = $refNote ? htmlspecialchars($refNote) : '';

        // Build table rows
        $rowsHtml = '';
        $idx = 0;
        foreach ($items as $it) {
            $idx++;
            $prod = htmlspecialchars($it['nome'] ?? ("Produto " . ($it['produto_id'] ?? '')));
            $q = htmlspecialchars((string)($it['quantidade'] ?? '0'));
            $loc = htmlspecialchars($it['local_nome'] ?? '—');
            $rowsHtml .= "<tr>
                <td style='width:40px; text-align:center; border:1px solid #ddd; padding:6px'>{$idx}</td>
                <td style='border:1px solid #ddd; padding:6px'>{$prod}</td>
                <td style='width:120px; text-align:center; border:1px solid #ddd; padding:6px'>{$q}</td>
                <td style='width:220px; border:1px solid #ddd; padding:6px'>{$loc}</td>
            </tr>";
        }

        $totalItems = intval($meta['total_items'] ?? count($items));
        $totalQty = number_format(floatval($meta['total_quantity'] ?? array_sum(array_column($items,'quantidade'))), 4, ',', '.');

        // Optional QR area (if show_qr and refNote provided, render as plain box; TCPDF can render barcode separately)
        $qrHtml = '';
        if ($show_qr && $refNoteEsc) {
            $qrHtml = "<div style='float:right; text-align:center;'>
                <div style='display:inline-block; border:1px solid #333; padding:8px; margin-left:8px;'>
                  <div style='font-size:10px; margin-bottom:6px;'>Ref</div>
                  <div style='font-weight:bold; font-size:12px;'>". $refNoteEsc ."</div>
                </div>
            </div>";
        }

        $html = <<<HTML
<!doctype html>
<html>
<head>
  <meta charset="utf-8"/>
  <title>Picklist</title>
  <style>
    body { font-family: DejaVu Sans, Arial, Helvetica, sans-serif; font-size:12px; color:#222; }
    .header { display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; }
    .company { font-weight:700; font-size:16px; }
    .meta { font-size:11px; color:#555; }
    table { width:100%; border-collapse:collapse; margin-top:6px; }
    th { background:#f3f3f3; border:1px solid #ddd; padding:8px; text-align:left; }
    td { padding:6px; vertical-align:top; }
    .footer { margin-top:14px; font-size:11px; color:#333; }
    .sign { margin-top:20px; display:flex; gap:40px; }
    .sign .box { border-top:1px solid #333; width:260px; padding-top:6px; text-align:center; color:#333; }
  </style>
</head>
<body>
  <div class="header">
    <div>
      <div class="company">{$company}</div>
      <div class="meta">Gerado em: {$date}</div>
      <div class="meta">{$refNoteEsc}</div>
    </div>
    <div>
      {$qrHtml}
    </div>
  </div>

  <table>
    <thead>
      <tr>
        <th style="width:40px;text-align:center">#</th>
        <th>Produto</th>
        <th style="width:120px;text-align:center">Quantidade</th>
        <th style="width:220px">Local</th>
      </tr>
    </thead>
    <tbody>
      {$rowsHtml}
    </tbody>
  </table>

  <div class="footer">
    <div>Total de linhas: <strong>{$totalItems}</strong> — Quantidade total: <strong>{$totalQty}</strong></div>
    <div class="sign">
      <div class="box">Conferi / Assinatura</div>
      <div class="box">Responsável / Assinatura</div>
    </div>
  </div>
</body>
</html>
HTML;
        return $html;
    }
}