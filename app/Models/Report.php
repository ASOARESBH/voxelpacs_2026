<?php

namespace App\Models;

class Report
{
    public int $id;
    public int $tenant_id;
    public int $estudo_id;
    public string $study_instance_uid;
    public int $usuario_id;
    public string $situacao;
    
    public ?string $secao_exame = null;
    public ?string $secao_tecnica = null;
    public ?string $secao_achados = null;
    public ?string $secao_conclusao = null;
    public ?string $secao_recomendacao = null;
    
    public ?int $template_id = null;
    public ?string $template_nome = null;
    
    public ?int $bloqueado_por = null;
    public ?string $bloqueado_em = null;
    
    public ?int $assinado_por = null;
    public ?string $assinado_em = null;
    public ?string $assinatura_hash = null;
    public ?string $assinatura_crm = null;
    
    public ?int $liberado_por = null;
    public ?string $liberado_em = null;
    
    public int $tempo_edicao_seg = 0;
    public ?string $ip_criacao = null;
    public ?string $ip_ultima_edicao = null;
    
    public string $created_at;
    public string $updated_at;

    /**
     * Preenche as propriedades do objeto com base em um array ou stdClass
     */
    public function fill(array|object $data): void
    {
        $arrayData = is_object($data) ? (array) $data : $data;
        
        foreach ($arrayData as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }
}

<?php
namespace App\Models;

use App\Core\Model;

class Report extends Model {
    protected string $table = 'reports';
    protected bool $hasTenant = true;

    public function findById(int $id): ?object {
        $sql = "SELECT * FROM {$this->table} WHERE id = :id" . $this->tenantWhere();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge(['id' => $id], $this->tenantParam()));
        return $stmt->fetch() ?: null;
    }

    public function findByEstudoId(int $estudoId): ?object {
        $sql = "SELECT * FROM {$this->table} WHERE bi_pacs_estudos_id = :estudo_id" . $this->tenantWhere();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge(['estudo_id' => $estudoId], $this->tenantParam()));
        return $stmt->fetch() ?: null;
    }

    public function findByStudyUid(string $studyUid): ?object {
        $sql = "SELECT * FROM {$this->table} WHERE study_instance_uid = :uid" . $this->tenantWhere();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge(['uid' => $studyUid], $this->tenantParam()));
        return $stmt->fetch() ?: null;
    }
}
