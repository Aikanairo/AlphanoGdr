<?php
declare(strict_types=1);
/**
 * Compatibility surface for the engine's historical PDF calls, rendered by Dompdf.
 * Dependency: dompdf/dompdf, installed by Composer with its upstream license.
 */
require_once dirname(__DIR__,2).'/vendor/autoload.php';
if (!defined('FPDF_VERSION')) define('FPDF_VERSION','dompdf-compat');

class FPDF
{
    protected string $html='';
    protected string $orientation='portrait';
    protected string|array $paper='A4';
    protected int $pages=0;
    protected string $font='DejaVu Sans';
    protected float $fontSize=11;
    protected string $color='#000000';
    protected array $metadata=[];
    public function __construct($orientation='P',$unit='mm',$format='A4') { $this->orientation=strtoupper((string)$orientation)==='L'?'landscape':'portrait'; $this->paper=$format; }
    public function FPDF($orientation='P',$unit='mm',$format='A4'): void { $this->__construct($orientation,$unit,$format); }
    public function AddPage($orientation=''): void { if ($this->pages++) $this->html.='<div style="page-break-before:always"></div>'; }
    public function PageNo(): int { return $this->pages; }
    public function SetTitle($value): void { $this->metadata['Title']=(string)$value; }
    public function SetAuthor($value): void { $this->metadata['Author']=(string)$value; }
    public function SetSubject($value): void { $this->metadata['Subject']=(string)$value; }
    public function SetKeywords($value): void { $this->metadata['Keywords']=(string)$value; }
    public function SetCreator($value): void { $this->metadata['Creator']=(string)$value; }
    public function SetFont($family,$style='',$size=0): void { $this->font='DejaVu Sans'; if ($size>0) $this->fontSize=(float)$size; }
    public function SetFontSize($size): void { $this->fontSize=max(1,(float)$size); }
    public function SetTextColor($red,$green=null,$blue=null): void { $this->color=sprintf('#%02x%02x%02x',(int)$red,(int)($green??$red),(int)($blue??$red)); }
    public function Cell($width,$height=0,$text='',$border=0,$line=0,$align='',$fill=0,$link='',$currentx=0): void {
        $this->html.='<span style="color:'.$this->color.';font-size:'.$this->fontSize.'pt">'.htmlspecialchars((string)$text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</span>'.($line?'<br>':'');
    }
    public function MultiCell($width,$height,$text,$border=0,$align='J',$fill=0,$link=''): void { $this->Cell($width,$height,$text,$border,1,$align,$fill,$link); }
    public function Write($height,$text,$currentx=0,$link=''): void { $this->Cell(0,$height,$text,0,0,'',0,$link); }
    public function Ln($height=null): void { $this->html.='<br>'; }
    public function Output($name='',$destination='') {
        $destination=strtoupper((string)($destination ?: ($name===''?'I':'F')));
        $options=new \Dompdf\Options(['isRemoteEnabled'=>false,'isPhpEnabled'=>false,'isJavascriptEnabled'=>false,'chroot'=>dirname(__DIR__,2),'defaultFont'=>'DejaVu Sans']);
        $pdf=new \Dompdf\Dompdf($options);
        $pdf->setPaper($this->paper,$this->orientation);
        $pdf->loadHtml('<!doctype html><html><meta charset="utf-8"><style>body{font-family:DejaVu Sans;font-size:11pt}</style><body>'.$this->html.'</body></html>','UTF-8');
        $pdf->render();
        foreach ($this->metadata as $key=>$value) $pdf->getCanvas()->get_cpdf()->addInfo($key,$value);
        $bytes=$pdf->output();
        if ($destination==='S') return $bytes;
        if ($destination==='F') { if (file_put_contents((string)$name,$bytes)===false) throw new RuntimeException('Salvataggio PDF non riuscito'); return ''; }
        if (!in_array($destination,['I','D'],true)) throw new InvalidArgumentException('Destinazione PDF non valida');
        header('Content-Type: application/pdf');
        $filename=preg_replace('/[^a-zA-Z0-9_.-]/','_',basename((string)$name)) ?: 'documento.pdf';
        header('Content-Disposition: '.($destination==='D'?'attachment':'inline').'; filename="'.$filename.'"');
        echo $bytes;
        return '';
    }
}
