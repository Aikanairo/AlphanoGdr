<?php
declare(strict_types=1);

/* ------------------------------------------------------------------
 *  libtext.inc.php
 *  • Parsing del “GML” proprietario → HTML sicuro
 *  • Utility di formattazione testo / date / MIME
 * ----------------------------------------------------------------*/

/* ==================================================================
 *  1) CLASSE GML → HTML
 * =================================================================*/
class GML
{
    private string $testo;

    /** tabella tag  */
    private array $tags = [
        "ce"  => ["stag"=>["<CENTER>","</CENTER>"],                            "stripspaces"=>false,"borderspaces"=>false,"depend"=>""],
        "tc"  => ["stag"=>['<span style="color:%s">','</span>'],               "stripspaces"=>false,"borderspaces"=>false,"depend"=>""],
        "lnk" => ["stag"=>['<a href="%s" target="_blank">','</a>'],            "stripspaces"=>true, "borderspaces"=>false,"depend"=>""],
        "bgc" => ["stag"=>['<div style="background-color:%s">','</div>'],      "stripspaces"=>false,"borderspaces"=>false,"depend"=>""],
        "bgi" => ["stag"=>["<div style=\"background-image:url('%s')\">","</div>"],"stripspaces"=>false,"borderspaces"=>false,"depend"=>""],
        "cl"  => ["stag"=>['<td class="Valore" colspan="%s">','</td>'],        "stripspaces"=>true, "borderspaces"=>true, "depend"=>"rg"],
        "rg"  => ["stag"=>["<tr>","</tr>"],                                    "stripspaces"=>true, "borderspaces"=>true, "depend"=>"tbl"],
        "tbl" => ["stag"=>['<table width="%s" height="%s">','</table>'],       "stripspaces"=>true, "borderspaces"=>false,"depend"=>""],
        "b"   => ["stag"=>["<strong>","</strong>"],                            "stripspaces"=>false,"borderspaces"=>false,"depend"=>""],
        "c"   => ["stag"=>["<em>","</em>"],                                    "stripspaces"=>false,"borderspaces"=>false,"depend"=>""],
        "i"   => ["stag"=>['<img width="%s" height="%s" src="','" alt="">'],   "stripspaces"=>true, "borderspaces"=>false,"depend"=>""],
        "p"   => ["stag"=>['<span class="Descrizione">','</span>'],            "stripspaces"=>false,"borderspaces"=>false,"depend"=>""],
        "q"   => ["stag"=>['<fieldset><legend class="Descrizione">%s ha scritto:</legend>','</fieldset>'],"stripspaces"=>false,"borderspaces"=>false,"depend"=>""],
        "t"   => ["stag"=>["<h1>","</h1>"],                                    "stripspaces"=>false,"borderspaces"=>false,"depend"=>""],
    ];

    public function __construct(string $rawText)
    {
        /* protezione caratteri “smart-quote” & affini */
        $this->testo = str_replace('&amp;#','&#', htmlentities($rawText, ENT_QUOTES, 'UTF-8'));
    }

    /** converte LF → <br> */
    private function nl2br(string $str): string
    {
        return str_replace("\n", '<br />', str_replace(chr(13), '', $str));
    }

    /* ───────────────────────── tag-driver generale ─────────────────────── */
    public function processText(): void
    {
        foreach ($this->tags as $key => $tag) {
            $this->processTag($key, $tag['borderspaces'], $tag['depend']);
        }
        /* escape delle parentesi quadre */
        $this->testo = str_replace(['\[','\]'], ['[',']'], $this->testo);
    }

    public function getText(): string
    {
        return $this->nl2br($this->testo);
    }

    /** Estrae solo il contenuto di un tag specifico, già con <br>. */
    public function getTag(string $tag): string
    {
        $expr = "\\[{$tag}.*?\\](.*?)\\[\\/{$tag}\\]";
        if (preg_match("/{$expr}/ism", $this->testo, $m)) {
            return $this->nl2br($m[1]);
        }
        return '';
    }

    /* ======================== PRIVATE ================================== */

    private function processTag(string $tag, bool $borderSpaces = false, string $depend = ''): void
    {
        $expr = "\\[{$tag}(\\]|\\040.*?\\])(.*?)\\[\\/{$tag}\\]";
        if ($borderSpaces)       $expr = "[\\040\\n\\r\\t]*?{$expr}[\\040\\n\\r\\t]*?";
        if (strlen($depend))     $expr = "(\\[{$depend}.*?\\].*?){$expr}(.*?\\[\\/{$depend}\\])";

        while (preg_match("/{$expr}/ism", $this->testo, $m)) {

            // pulizia della ] conclusiva
            if ($depend) { $m[2] = substr($m[2], 0, -1); }
            else         { $m[1] = substr($m[1], 0, -1); }

            // metodo specifico?
            $meth = "tag_{$tag}";
            if (method_exists($this, $meth)) {
                $this->$meth($m);
            } else {
                $this->general($tag, $m);
            }
        }
    }

    /** sostituzione standard */
    private function general(string $tag, array $m): void
    {
        $cfg = $this->tags[$tag];
        if ($cfg['depend']) {
            if ($cfg['stripspaces']) $m[3] = trim($m[3]);
            $rep = $m[1] . $cfg['stag'][0] . $m[3] . $cfg['stag'][1] . $m[4];
        } else {
            if ($cfg['stripspaces']) $m[2] = trim($m[2]);
            $rep = $cfg['stag'][0] . $m[2] . $cfg['stag'][1];
        }
        $this->testo = str_replace($m[0], $rep, $this->testo);
    }

    /* ---------- tag personalizzati ---------- */
    private function tag_tc(array $m): void
    {
        $this->testo = str_replace(
            $m[0],
            sprintf($this->tags['tc']['stag'][0], trim($m[1])) . $m[2] . $this->tags['tc']['stag'][1],
            $this->testo
        );
    }

    private function tag_i(array $m): void
    {
        // estrai in sicurezza le dimensioni "LxA"
        $parts = explode('x', trim($m[1]));
        $w     = $parts[0] ?? '';
        $h     = $parts[1] ?? '';
        $src   = trim($m[2]);

        // ricostruisci il tag <img> con valori di fallback
        $this->testo = str_replace(
            $m[0],
            sprintf(
                $this->tags['i']['stag'][0],
                $w,
                $h
            )
            . $src
            . $this->tags['i']['stag'][1],
            $this->testo
        );
    }


    private function tag_lnk(array $m): void
    {
        if (!trim($m[1])) $m[1] = $m[2];
        $this->testo = str_replace(
            $m[0],
            sprintf($this->tags['lnk']['stag'][0], trim($m[1])) . $m[2] . $this->tags['lnk']['stag'][1],
            $this->testo
        );
    }

    private function tag_bgc(array $m): void
    {
        $this->testo = str_replace(
            $m[0],
            sprintf($this->tags['bgc']['stag'][0], trim($m[1])) . $m[2] . $this->tags['bgc']['stag'][1],
            $this->testo
        );
    }

    private function tag_bgi(array $m): void
    {
        $this->testo = str_replace(
            $m[0],
            sprintf($this->tags['bgi']['stag'][0], trim($m[1])) . $m[2] . $this->tags['bgi']['stag'][1],
            $this->testo
        );
    }

    private function tag_cl(array $m): void
    {
        $this->testo = str_replace(
            $m[0],
            $m[1] .
            sprintf($this->tags['cl']['stag'][0], trim($m[2])) .
            $m[3] .
            $this->tags['cl']['stag'][1] .
            $m[4],
            $this->testo
        );
    }

    private function tag_tbl(array $m): void
    {
        [$w,$h] = explode('x', trim($m[1]));
        $this->testo = str_replace(
            $m[0],
            sprintf($this->tags['tbl']['stag'][0], $w, $h) . $m[2] . $this->tags['tbl']['stag'][1],
            $this->testo
        );
    }

    private function tag_q(array $m): void
    {
        $this->testo = str_replace(
            $m[0],
            sprintf($this->tags['q']['stag'][0], trim($m[1])) . $m[2] . $this->tags['q']['stag'][1],
            $this->testo
        );
    }
}

/* ==================================================================
 *  2) FUNZIONI DI COMODO
 * =================================================================*/

/** ritorna il testo GML convertito in HTML */
function ProcessText(string $testo): string
{
    if (preg_match('/<\/?[a-z][^>]*>/i', $testo)) {
        return alphano_sanitize_html($testo);
    }
    $gml = new GML($testo);
    $gml->processText();
    return alphano_sanitize_html($gml->getText());
}

/** HTML di editor e GML: conserva la formattazione, elimina codice e URL eseguibili. */
function alphano_sanitize_html(string $html): string
{
    $document = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $allowed = ['p','div','span','br','strong','b','em','i','u','s','ol','ul','li','a','img','table','thead','tbody','tfoot','tr','th','td','h1','h2','h3','h4','h5','h6','blockquote','pre','code','center','fieldset','legend','hr','font','sub','sup'];
    $drop = ['script','style','iframe','object','embed','form','input','button','textarea','select','link','meta','base','svg','math'];
    $safeUrl = static function (string $value, bool $image = false): bool {
        $value = preg_replace('/[\x00-\x20\x7f]+/', '', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($value === '') return false;
        if (preg_match('~^(?:https?://|/|\#|\?|\./|\.\./)~i', $value)) return true;
        if (!$image && str_starts_with(strtolower($value), 'mailto:')) return true;
        return !preg_match('~^[a-z][a-z0-9+.-]*:~i', $value) && !str_contains($value, '\\');
    };
    $clean = function (DOMNode $node) use (&$clean, $document, $allowed, $drop, $safeUrl): void {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if (!$child instanceof DOMElement) {
                if ($child instanceof DOMComment || $child instanceof DOMProcessingInstruction) $node->removeChild($child);
                continue;
            }
            $tag = strtolower($child->tagName);
            if (in_array($tag, $drop, true)) { $node->removeChild($child); continue; }
            $clean($child);
            if (!in_array($tag, $allowed, true)) {
                while ($child->firstChild) $node->insertBefore($child->firstChild, $child);
                $node->removeChild($child);
                continue;
            }
            foreach (iterator_to_array($child->attributes) as $attribute) {
                $name = strtolower($attribute->name);
                $value = $attribute->value;
                $keep = false;
                if (in_array($name, ['title','alt'], true)) $keep = true;
                elseif ($name === 'class') $keep = (bool)preg_match('/^[a-zA-Z0-9 _-]{0,150}$/D', $value);
                elseif (in_array($name, ['width','height','colspan','rowspan','border','cellpadding','cellspacing'], true)) $keep = (bool)preg_match('/^\d{1,4}%?$/D', $value);
                elseif ($name === 'align') $keep = in_array(strtolower($value), ['left','right','center','justify'], true);
                elseif ($name === 'color' || $name === 'bgcolor') $keep = (bool)preg_match('/^(#[a-f0-9]{3,8}|[a-z]{1,25})$/iD', $value);
                elseif ($name === 'href' && $tag === 'a') $keep = $safeUrl($value);
                elseif ($name === 'src' && $tag === 'img') $keep = $safeUrl($value, true);
                elseif ($name === 'name' || $name === 'id') $keep = (bool)preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,100}$/D', $value);
                elseif ($name === 'style') {
                    $styles = [];
                    foreach (explode(';', $value) as $rule) {
                        $parts = explode(':', $rule, 2);
                        if (count($parts) !== 2) continue;
                        [$property, $setting] = array_map('trim', $parts);
                        $property = strtolower($property);
                        if (in_array($property, ['color','background-color'], true)
                            && preg_match('/^(#[a-f0-9]{3,8}|[a-z]{1,25}|rgba?\([\d\s.,%]+\)|hsla?\([\d\s.,%]+\))$/iD', $setting)) $styles[] = "$property:$setting";
                        elseif ($property === 'text-align' && in_array($setting, ['left','right','center','justify'], true)) $styles[] = "$property:$setting";
                        elseif (in_array($property, ['font-weight','font-style','text-decoration','white-space'], true)
                            && preg_match('/^[a-z0-9 -]{1,30}$/iD', $setting)) $styles[] = "$property:$setting";
                        elseif (in_array($property, ['font-size','width','height','max-width','line-height'], true)
                            && preg_match('/^\d{1,4}(\.\d{1,2})?(px|em|rem|%|pt)?$/iD', $setting)) $styles[] = "$property:$setting";
                        elseif ($property === 'background-image' && preg_match('~^url\(["\x27]?([^"\x27()]+)["\x27]?\)$~iD', $setting, $url) && $safeUrl($url[1], true)) $styles[] = "background-image:url('" . str_replace("'", '%27', $url[1]) . "')";
                    }
                    if ($styles) { $child->setAttribute('style', implode(';', $styles)); $keep = true; }
                }
                if (!$keep) $child->removeAttribute($attribute->name);
            }
            if ($tag === 'a') { $child->setAttribute('target', '_blank'); $child->setAttribute('rel', 'noopener noreferrer'); }
        }
    };
    $body = $document->getElementsByTagName('body')->item(0);
    if (!$body) return '';
    $clean($body);
    $result = '';
    foreach ($body->childNodes as $child) $result .= $document->saveHTML($child);
    return $result;
}

/**
 * Estrae il contenuto del tag [t]…[/t] (titolo).
 */
function GetTitolo(string $testo): string
{
    $gml = new GML($testo);
    return $gml->getTag('t');
}

