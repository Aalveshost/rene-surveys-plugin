<div class="wrap rene-builder-wrap">
    <h1>Survey Builder / Gerador de Questionários</h1>
    <p>Crie a estrutura do seu questionário abaixo e salve no CPT <strong>Questionários</strong>.</p>

    <div class="rs-builder-card">
        <div class="rs-field-group">
            <label>Slug da Página (Identificador):</label>
            <input type="text" id="rs-target-slug" placeholder="ex: vinci" class="regular-text">
            <p class="description">Este slug deve ser o mesmo da URL onde o form será exibido.</p>
        </div>

        <div id="rs-questions-list" class="rs-questions-list">
            <!-- Questions added here -->
        </div>

        <div class="rs-builder-actions">
            <button type="button" id="rs-add-question" class="button button-secondary">+ Adicionar Questão</button>
            <button type="button" id="rs-save-json" class="button button-primary">Gerar JSON e Salvar no CPT</button>
        </div>
    </div>

    <div class="rs-debug-output">
        <h3>Preview JSON:</h3>
        <pre id="rs-json-preview">[]</pre>
    </div>
</div>

<style>
.rs-builder-card {
    background: #fff;
    padding: 20px;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    margin-top: 20px;
    max-width: 800px;
}
.rs-field-group {
    margin-bottom: 20px;
}
.rs-question-item {
    background: #f8f9fa;
    padding: 15px;
    border: 1px solid #ddd;
    border-radius: 4px;
    margin-bottom: 15px;
    position: relative;
}
.rs-question-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}
.rs-question-item input[type="text"] {
    width: 100%;
    margin-bottom: 10px;
}
.rs-remove-qt {
    color: #a00;
    cursor: pointer;
    text-decoration: underline;
}
.rs-debug-output {
    margin-top: 30px;
    max-width: 800px;
}
#rs-json-preview {
    background: #272822;
    color: #f8f8f2;
    padding: 15px;
    border-radius: 4px;
    overflow-x: auto;
}
</style>
