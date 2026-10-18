<?php
/**
 * modules/application_documents/logic/ApplicationDocumentController.php — dokument wniosku złożonego
 * elektronicznie (podgląd do druku i PDF). Dostęp: autor wniosku albo admin/redaktor.
 */

require_once __DIR__ . '/applicationDocuments.php';

final class ApplicationDocumentController
{
    private const VIEW = __DIR__ . '/../view/';

    /** @return array{0: array, 1: array} wniosek i wzór; kończy żądanie 404/403 przy braku. */
    private function load(int $id): array {
        $app = appDocsLoad($id);
        $tpl = $app ? appDocsTemplateFor($app['type_name'] ?? null) : null;
        if (!$app || !$tpl) { http_response_code(404); echo 'Nie znaleziono dokumentu wniosku.'; exit; }
        if (!appDocsCanView($app)) { http_response_code(403); echo 'Brak dostępu do tego wniosku.'; exit; }
        return [$app, $tpl];
    }

    public function show(int $id): string {
        [$app, $tpl] = $this->load($id);
        return Router::view(self::VIEW . 'document.php', ['app' => $app, 'tpl' => $tpl, 'kind' => 'doc',
            'css' => appDocsCss(), 'body' => appDocsHtml($app, $tpl), 'pdfUrl' => appDocsUrl($id, true),
            'otherUrl' => appDocsReceiptUrl($id), 'otherLabel' => 'Potwierdzenie złożenia']);
    }

    public function receipt(int $id): string {
        [$app, $tpl] = $this->load($id);
        return Router::view(self::VIEW . 'document.php', ['app' => $app, 'tpl' => $tpl, 'kind' => 'receipt',
            'css' => appDocsReceiptCss(), 'body' => appDocsReceiptHtml($app, $tpl), 'pdfUrl' => appDocsReceiptUrl($id, true),
            'otherUrl' => appDocsUrl($id), 'otherLabel' => 'Treść wniosku']);
    }

    public function receiptPdf(int $id): ?string {
        [$app, $tpl] = $this->load($id);
        return $this->sendPdf(appDocsReceiptPdf($app, $tpl), 'UPZ_' . appDocsNumber($app, $tpl));
    }

    public function pdf(int $id): ?string {
        [$app, $tpl] = $this->load($id);
        return $this->sendPdf(appDocsPdf($app, $tpl), appDocsNumber($app, $tpl));
    }

    private function sendPdf(?string $pdf, string $name): ?string {
        if ($pdf === null) { http_response_code(500); return 'Nie udało się przygotować PDF — spróbuj wydrukować podgląd.'; }
        $file = str_replace('/', '_', $name) . '.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $file . '"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        return null;
    }
}
