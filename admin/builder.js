jQuery(document).ready(function($) {
    let questionCount = 0;

    function renderQuestions() {
        const questions = [];
        $('.rs-question-item').each(function() {
            const item = $(this);
            const id = item.data('id');
            const label = item.find('.rs-qt-label').val();
            const type = item.find('.rs-qt-type').val();
            
            const q = { id: id, label: label, type: type };
            if (type === 'multiple') {
                q.options = ['Entrevias', 'Cristais']; // Default mock for now as per image 1
                // We could add more options here if needed
            }
            questions.push(q);
        });
        $('#rs-json-preview').text(JSON.stringify(questions, null, 2));
    }

    $('#rs-add-question').on('click', function() {
        questionCount++;
        const id = 'q' + questionCount + '_' + Date.now();
        const html = `
            <div class="rs-question-item" data-id="${id}">
                <div class="rs-question-header">
                    <strong>Questão #${questionCount}</strong>
                    <span class="rs-remove-qt">Remover</span>
                </div>
                <input type="text" class="rs-qt-label" placeholder="Digite a pergunta aqui..." value="">
                <div class="rs-field-group">
                    <label>Tipo:</label>
                    <select class="rs-qt-type">
                        <option value="multiple">Múltipla Escolha (Padrão)</option>
                        <option value="text">Campo de Texto (Descritiva)</option>
                    </select>
                </div>
            </div>
        `;
        $('#rs-questions-list').append(html);
        renderQuestions();
    });

    $(document).on('change', '.rs-qt-label, .rs-qt-type', function() {
        renderQuestions();
    });

    $(document).on('click', '.rs-remove-qt', function() {
        $(this).closest('.rs-question-item').remove();
        renderQuestions();
    });

    $('#rs-save-json').on('click', function() {
        const slug = $('#rs-target-slug').val();
        const json = $('#rs-json-preview').text();

        if (!slug) {
            alert('Por favor, informe o slug da página.');
            return;
        }

        const data = {
            action: 'rene_save_questionnaire',
            nonce: ReneBuilderData.nonce,
            slug: slug,
            questions_data: json
        };

        const $btn = $(this);
        $btn.prop('disabled', true).text('Salvando...');

        $.post(ajaxurl, data, function(response) {
            if (response.success) {
                alert('Questionário salvo com sucesso!');
            } else {
                alert('Erro ao salvar: ' + response.data.message);
            }
            $btn.prop('disabled', false).text('Gerar JSON e Salvar no CPT');
        });
    });
});
