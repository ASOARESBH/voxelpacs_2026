<?php

namespace App\Services;

use PhpOffice\PhpWord\Element\AbstractElement;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\IOFactory;

/**
 * Analisa arquivos DOCX de máscaras sem processos externos.
 *
 * Contrato:
 * - Heading* inicia uma máscara;
 * - rótulos em negrito mapeiam Técnica/Achados/Impressão;
 * - texto sem seção nunca é descartado: vai para Achados;
 * - texto de DOCX é escapado antes de ser transformado em HTML permitido.
 */
final class MascaraDocxImportService
{
    /** @var array<string,string> */
    private const LABELS = [
        'tecnica' => 'tecnica',
        'metodo' => 'tecnica',
        'analise' => 'achados',
        'achados' => 'achados',
        'achados adicionais' => 'achados',
        'impressao' => 'conclusao',
        'impressao diagnostica' => 'conclusao',
        'conclusao' => 'conclusao',
    ];

    /** @var array<string,string> */
    private const MODALIDADES = [
        'angiotomografia' => 'CT',
        'tomografia' => 'CT',
        'ressonancia' => 'MR',
        'raio x' => 'CR',
        'raiox' => 'CR',
        'radiografia' => 'CR',
        'ultrassonografia' => 'US',
        'ultrasonografia' => 'US',
        'ecografia' => 'US',
        'mamografia' => 'MG',
    ];

    /**
     * @return array<int,array{nome:string,modalidade:string,secao_tecnica:string,secao_achados:string,secao_conclusao:string,revisar:bool,study_description_tag:string}>
     */
    public function analisar(string $arquivo): array
    {
        if (!class_exists(IOFactory::class)) {
            throw new \RuntimeException('O parser DOCX não está instalado. Execute composer install no ambiente de produção.');
        }
        if (!is_file($arquivo) || !is_readable($arquivo)) {
            throw new \InvalidArgumentException('Arquivo DOCX temporário indisponível.');
        }

        try {
            $documento = IOFactory::load($arquivo);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('Não foi possível ler este DOCX. Confirme se o arquivo não está corrompido.', 0, $e);
        }

        $mascaras = [];
        $atual = null;

        foreach ($documento->getSections() as $section) {
            foreach ($section->getElements() as $elemento) {
                $paragrafo = $this->extrairParagrafo($elemento);
                if ($paragrafo === null || $paragrafo['texto'] === '') {
                    continue;
                }

                if ($this->ehHeading($elemento)) {
                    $this->finalizarMascara($mascaras, $atual);
                    $atual = $this->novaMascara($paragrafo['texto']);
                    continue;
                }

                if ($atual === null) {
                    // Conteúdo antes do primeiro Heading não pertence a uma máscara.
                    continue;
                }

                $detalhe = $this->detectarRotulo($paragrafo['runs']);
                if ($detalhe['secao'] !== null) {
                    $atual['teve_secao_reconhecida'] = true;
                    $atual['secao_atual'] = $detalhe['secao'];
                    if ($detalhe['conteudo_html'] !== '') {
                        $this->adicionarConteudo($atual, $detalhe['secao'], $detalhe['conteudo_html']);
                    }
                    continue;
                }

                $html = $paragrafo['html'];
                if ($detalhe['rotulo_desconhecido']) {
                    // Indicação, Medidas e demais rótulos sem campo próprio
                    // nunca são descartados: ficam em Achados para revisão.
                    $this->adicionarConteudo($atual, 'achados', $html);
                    $atual['secao_atual'] = 'achados';
                } elseif ($atual['secao_atual'] === null) {
                    $atual['sem_secao'][] = $html;
                } else {
                    $this->adicionarConteudo($atual, $atual['secao_atual'], $html);
                }
            }
        }

        $this->finalizarMascara($mascaras, $atual);

        if (!$mascaras) {
            throw new \InvalidArgumentException('Nenhum título Heading foi encontrado no DOCX.');
        }

        return $mascaras;
    }

    /** @return array<string,mixed> */
    private function novaMascara(string $nome): array
    {
        $nome = trim($nome);
        $modalidade = $this->sugerirModalidade($nome);

        return [
            'nome' => $nome,
            'modalidade' => $modalidade,
            'study_description_tag' => $nome,
            'secao_tecnica' => '',
            'secao_achados' => '',
            'secao_conclusao' => '',
            'revisar' => $modalidade === '',
            'teve_secao_reconhecida' => false,
            'secao_atual' => null,
            'sem_secao' => [],
        ];
    }

    /** @param array<int,array<string,mixed>> $mascaras */
    private function finalizarMascara(array &$mascaras, ?array &$atual): void
    {
        if ($atual === null) {
            return;
        }

        $buffer = implode('', $atual['sem_secao']);
        if ($buffer !== '') {
            $atual['secao_achados'] = $this->unirHtml($buffer, (string) $atual['secao_achados']);
        }

        if (!$atual['teve_secao_reconhecida']) {
            $atual['revisar'] = true;
        }

        unset($atual['teve_secao_reconhecida'], $atual['secao_atual'], $atual['sem_secao']);
        $mascaras[] = $atual;
        $atual = null;
    }

    /** @param array<string,mixed> $mascara */
    private function adicionarConteudo(array &$mascara, string $secao, string $html): void
    {
        $campo = 'secao_' . $secao;
        $mascara[$campo] = $this->unirHtml((string) ($mascara[$campo] ?? ''), $html);
    }

    private function unirHtml(string $esquerda, string $direita): string
    {
        $esquerda = trim($esquerda);
        $direita = trim($direita);
        if ($esquerda === '') return $direita;
        if ($direita === '') return $esquerda;
        return $esquerda . $direita;
    }

    /**
     * @return array{texto:string,html:string,runs:array<int,array{text:string,bold:bool,italic:bool}>}|null
     */
    private function extrairParagrafo(AbstractElement $elemento): ?array
    {
        $runs = $this->extrairRuns($elemento);
        if (!$runs) {
            return null;
        }

        $texto = trim(implode('', array_column($runs, 'text')));
        if ($texto === '') {
            return null;
        }

        return [
            'texto' => $texto,
            'html' => $this->runsParaHtml($runs),
            'runs' => $runs,
        ];
    }

    /** @return array<int,array{text:string,bold:bool,italic:bool}> */
    private function extrairRuns(AbstractElement $elemento): array
    {
        $runs = [];

        if ($elemento instanceof TextRun && method_exists($elemento, 'getElements')) {
            foreach ($elemento->getElements() as $filho) {
                $this->adicionarRun($runs, $filho);
            }
            return $runs;
        }

        $this->adicionarRun($runs, $elemento);
        return $runs;
    }

    /** @param array<int,array{text:string,bold:bool,italic:bool}> $runs */
    private function adicionarRun(array &$runs, mixed $elemento): void
    {
        if (is_object($elemento) && method_exists($elemento, 'getElements')) {
            foreach ($elemento->getElements() as $filho) {
                $this->adicionarRun($runs, $filho);
            }
            return;
        }

        if ($elemento instanceof Text) {
            $texto = (string) $elemento->getText();
            if ($texto !== '') {
                $runs[] = [
                    'text' => $texto,
                    'bold' => $this->estiloAtivo($elemento, 'isBold', 'bold'),
                    'italic' => $this->estiloAtivo($elemento, 'isItalic', 'italic'),
                ];
            }
            return;
        }

        if (is_object($elemento) && method_exists($elemento, 'getText')) {
            $texto = $elemento->getText();
            if (is_scalar($texto) && (string) $texto !== '') {
                $runs[] = ['text' => (string) $texto, 'bold' => false, 'italic' => false];
            }
        }
    }

    private function estiloAtivo(object $elemento, string $metodo, string $chave): bool
    {
        if (!method_exists($elemento, 'getFontStyle')) return false;
        $estilo = $elemento->getFontStyle();
        if (is_array($estilo)) return !empty($estilo[$chave]);
        if (is_object($estilo) && method_exists($estilo, $metodo)) return (bool) $estilo->{$metodo}();
        return false;
    }

    private function ehHeading(AbstractElement $elemento): bool
    {
        foreach (['getParagraphStyle', 'getStyle'] as $metodo) {
            if (!method_exists($elemento, $metodo)) continue;
            $estilo = $elemento->{$metodo}();
            $nome = $this->nomeEstilo($estilo);
            if ($nome !== '' && preg_match('/^Heading/i', $nome)) return true;
        }
        return false;
    }

    private function nomeEstilo(mixed $estilo): string
    {
        if (is_string($estilo)) return trim($estilo);
        if (is_object($estilo)) {
            foreach (['getName', 'getStyleName'] as $metodo) {
                if (method_exists($estilo, $metodo)) {
                    return trim((string) $estilo->{$metodo}());
                }
            }
        }
        return '';
    }

    /**
     * @param array<int,array{text:string,bold:bool,italic:bool}> $runs
     * @return array{secao:?string,conteudo_html:string,rotulo_desconhecido:bool}
     */
    private function detectarRotulo(array $runs): array
    {
        $primeiroIndice = null;
        foreach ($runs as $indice => $run) {
            if (trim($run['text']) !== '') {
                $primeiroIndice = $indice;
                break;
            }
        }
        if ($primeiroIndice === null || empty($runs[$primeiroIndice]['bold'])) {
            return ['secao' => null, 'conteudo_html' => '', 'rotulo_desconhecido' => false];
        }

        $primeiro = $runs[$primeiroIndice];
        $textoPrimeiro = trim($primeiro['text']);
        $posDoisPontos = strpos($textoPrimeiro, ':');
        $candidato = trim($posDoisPontos === false ? $textoPrimeiro : substr($textoPrimeiro, 0, $posDoisPontos));
        $secao = self::LABELS[$this->normalizar($candidato)] ?? null;

        if ($secao === null) {
            $proximoEhDoisPontos = isset($runs[$primeiroIndice + 1]) && trim($runs[$primeiroIndice + 1]['text']) === ':';
            $pareceRotulo = $posDoisPontos !== false || $proximoEhDoisPontos || strlen($candidato) <= 40;
            return ['secao' => null, 'conteudo_html' => '', 'rotulo_desconhecido' => $pareceRotulo];
        }

        $restantes = $runs;
        if ($posDoisPontos === false) {
            unset($restantes[$primeiroIndice]);
        } else {
            $apos = substr($primeiro['text'], $posDoisPontos + 1);
            if (trim($apos) === '') {
                unset($restantes[$primeiroIndice]);
            } else {
                $restantes[$primeiroIndice]['text'] = ltrim($apos);
            }
        }

        $html = $this->runsParaHtml(array_values($restantes));
        $html = preg_replace('/^<p>\s*:\s*/u', '<p>', $html) ?? $html;
        return ['secao' => $secao, 'conteudo_html' => $html, 'rotulo_desconhecido' => false];
    }

    /** @param array<int,array{text:string,bold:bool,italic:bool}> $runs */
    private function runsParaHtml(array $runs): string
    {
        $html = '';
        foreach ($runs as $run) {
            $texto = htmlspecialchars($run['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            if ($run['bold']) $texto = '<strong>' . $texto . '</strong>';
            if ($run['italic']) $texto = '<em>' . $texto . '</em>';
            $html .= $texto;
        }
        return trim($html) === '' ? '' : '<p>' . $html . '</p>';
    }

    private function sugerirModalidade(string $nome): string
    {
        $normalizado = $this->normalizar($nome);
        foreach (self::MODALIDADES as $palavra => $modalidade) {
            if (strpos($normalizado, $palavra) !== false) return $modalidade;
        }
        return '';
    }

    private function normalizar(string $valor): string
    {
        $valor = trim(strtolower($valor));
        $valor = strtr($valor, [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a',
            'é' => 'e', 'ê' => 'e', 'í' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ü' => 'u', 'ç' => 'c',
        ]);
        $valor = preg_replace('/\s+/u', ' ', $valor) ?? $valor;
        return trim($valor, " \t\n\r\0\x0B:");
    }
}
