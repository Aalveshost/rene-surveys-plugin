# FormSync Excel WP – Plugin WordPress (Sistema de Pesquisas Dinâmicas)

## Estrutura de Arquivos

```
survey-plugin/
├── formsync-excel-wp.php      # Plugin principal
├── admin/
│   ├── builder.php           # HTML da interface de criação de questionários (Admin)
│   └── builder.js            # Lógica JS do Builder
├── public/
│   ├── script.js             # Renderização do formulário no front-end + AJAX
│   └── style.css             # CSS premium Dark Mode
└── includes/
    └── excel-sync.php        # Cron Job + placeholder de integração Excel
```

---

## 1. Instalação

1. Copie a pasta `survey-plugin/` para dentro de `wp-content/plugins/`.
2. Acesse **WP Admin > Plugins** e ative **FormSync Excel WP**.

---

## 2. CPTs Necessários no JetEngine

Você precisa criar dois CPTs manualmente no JetEngine:

### CPT 1: `questionarios`
| Meta Field | Tipo | Descrição |
|---|---|---|
| `page_slug` | Text | Slug da página que usará este questionário |
| `questions_data` | Textarea / Text | JSON gerado pelo Builder |

### CPT 2: `respostas_survey`
| Meta Field | Tipo | Descrição |
|---|---|---|
| `survey_answers` | Textarea / Text | JSON com as respostas do usuário |
| `excel_sync_status` | Text | `pending`, `synced`, ou `failed` |
| `excel_sync_date` | Date/Text | Data da sincronização |

---

## 3. Como Criar um Questionário (Fluxo do Administrador)

1. Acesse **WP Admin > Survey Builder**.
2. Informe o **Slug da Página** (ex: `vinci` para `ssp.seg.br/pesquisas/vinci/`).
3. Clique em **+ Adicionar Questão** para cada pergunta.
4. Para cada questão, escolha o tipo:
   - **Múltipla Escolha** – exibe botões de rádio com as opções
   - **Descritiva** – exibe um campo de texto livre
5. Clique em **Gerar JSON e Salvar no CPT**.
6. O plugin cria (ou atualiza) automaticamente um post no CPT `questionarios` com o JSON.

---

## 4. Como Exibir o Formulário (Shortcode)

Na página WordPress (ex: `ssp.seg.br/pesquisas/vinci/`), insira o shortcode:

```
[render_survey page_slug="vinci"]
```

> Ou simplesmente `[render_survey]` — o plugin irá usar automaticamente a slug da página atual.

---

## 5. Fluxo de Resposta do Usuário

Quando o usuário preencher e enviar o formulário:

1. O JS envia via AJAX as respostas para o endpoint `rene_submit_survey`.
2. O PHP cria um post no CPT `respostas_survey` com o título:
   ```
   #vinci - #1234
   ```
3. O meta `survey_answers` fica salvo assim:
   ```json
   {
     "q1": "Entrevias",
     "q2": "Acima de 1 até 3 anos",
     "q31": "Sim, por cumprir meta de segurança",
     "q32": "Aqui o colaborador escreveu sua sugestão..."
   }
   ```

---

## 6. Sincronização com Excel (Cron)

O Cron roda a **cada hora** e processa todos os posts de resposta com status `pending`.

### Integrando seu script:

Abra `includes/excel-sync.php` e edite a função `rene_custom_excel_export_handler`:

```php
function rene_custom_excel_export_handler($post_id, $slug, $answers) {

    // Exemplo: enviar os dados para um script PHP externo via cURL
    $endpoint = 'https://ssp.seg.br/scripts/push-excel.php';
    
    $response = wp_remote_post($endpoint, array(
        'body' => array(
            'post_id' => $post_id,
            'empresa' => strtoupper($slug),
            'respostas' => json_encode($answers)
        )
    ));

    return !is_wp_error($response);
}
```

---

## 7. Gatilho Manual (Teste)

Para forçar o Cron sem esperar uma hora, você pode testar com:

```php
// Cole temporariamente no functions.php do tema
add_action('init', function() {
    if (isset($_GET['force_sync'])) {
        rene_run_excel_sync();
    }
});
// Acesse: seusite.com/?force_sync=1
```
