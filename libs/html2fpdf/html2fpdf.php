<?php
declare(strict_types=1);
/** Dompdf-backed adapter for the legacy HTML2FPDF call surface. */
require_once __DIR__.'/fpdf.php';
if (!defined('HTML2FPDF_VERSION')) define('HTML2FPDF_VERSION','dompdf-compat');
class HTML2FPDF extends FPDF
{
    public function HTML2FPDF($orientation='P',$unit='mm',$format='A4'): void { $this->__construct($orientation,$unit,$format); }
    public function UseCSS($enabled=true): void { /* Dompdf reads CSS by default. */ }
    public function WriteHTML($html): void {
        $this->html.=function_exists('alphano_sanitize_html') ? alphano_sanitize_html((string)$html) : (string)$html;
    }
}
