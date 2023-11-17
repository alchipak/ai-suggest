jQuery(document).ready(function($) {

    // Global interaction counter and request state variable
    var interactionCount = 0;
    var isRequestPending = false;

    // Set the initial message when creating a new post
    $('#ai-suggest-suggestions').text(aiSuggestLocalize.notEnoughContent);

    // Global variables to store Gutenberg editor's title and content
    var title_g = '';
    var content_g = '';
    
    // Checks if the Gutenberg editor is fully loaded and resolves the promise if so
    function checkGutenbergLoaded(resolve) {
        title_g = wp.data.select('core/editor').getEditedPostAttribute('title');
        content_g = wp.data.select('core/editor').getEditedPostContent();
    
        if (title_g && content_g) {
            resolve(); // Both title and content are loaded
        } else {
            setTimeout(function() { checkGutenbergLoaded(resolve); }, 500);
        }
    }

    // Error handling for unknown errors like Uncaught TypeError
    try {
            // Code to handle initial load and subsequent interactions...
            // AJAX calls to fetch and post data, including error handling and displaying messages
            function getSuggestions(isInitialLoad = false) {
                return new Promise(function(resolve) {
                    if (isInitialLoad) {
                        wp.domReady(function() {
                            checkGutenbergLoaded(resolve);
                        });
                    } else {
                        resolve();
                    }
                }).then(function() {
                    if (!title_g || !content_g) {
                        $('#ai-suggest-suggestions').text(aiSuggestLocalize.notEnoughContent);
                        return;
                    }

                    if (isInitialLoad) {
                        title = title_g;
                        content = content_g;
                    } else if (interactionCount < 100) {
                        return;
                    }

                    if (!title || !content) {
                        $('#ai-suggest-suggestions').text(aiSuggestLocalize.notEnoughContent);
                        isRequestPending = false;
                        return;
                    }

                    if (isRequestPending) {
                        console.log(aiSuggestLocalize.requestInProgress);
                        return;
                    }

                    if (!isInitialLoad) {
                        title = wp.data.select('core/editor').getEditedPostAttribute('title');
                        content = wp.data.select('core/editor').getEditedPostContent();
                    }

                    cleanContent = cleanGutenbergContent(content);

                    var dataToSend = {
                        action: 'ai_suggest_get_gpt_suggestions',
                        title: title,
                        content: cleanContent
                    };

                    console.log('Data sent:', dataToSend);

                    isRequestPending = true;
                    $('#ai-suggest-suggestions').text(aiSuggestLocalize.generatingSuggestions);


                    $.post(aiSuggestAjax.ajax_url, dataToSend, function(response) {
                        
                        if(response.success) {
                            var suggestionsContent = response.data.choices.map(choice => choice.message.content);
                            suggestionsContent.forEach(function(content) {
                                $('#ai-suggest-suggestions').empty();
                                var suggestions = content.split('|');
                                suggestions.forEach(function(suggestion) {
                                    if (suggestion.trim() !== '') {
                                        var parts = suggestion.split(':');
                                        var title = parts[0] && parts[0].trim();
                                        var description = parts[1] && parts[1].trim();
                                        var row = '<div class="ai-suggest-row" style="justify-content: space-between; align-items: center;">' +
                                        '<div class="ai-suggest-title" style="flex-grow: 1;"><b><span class="titulo-post">' + title + '</span></b>: <span class="conteudo-post">' + description + '</span></div>' +
                                        '<button class="ai-suggest-save-btn" style="background-color: green; color: white; padding: 5px 10px; border: none; cursor: pointer;">' + aiSuggestLocalize.save + '</button>' +
                                        '<button class="ai-suggest-generate-btn" style="background-color: white; color: black; padding: 5px 10px; border: 1px solid #ccc; cursor: pointer; margin-left: 5px;">' + aiSuggestLocalize.generate + '</button>' +
                                        '</div>';
                                        $('#ai-suggest-suggestions').append(row);
                                    }
                                    
                                });
                            });
                        } else {
                            console.error(aiSuggestLocalize.unknownError, response.error);
                            $('#ai-suggest-suggestions').html('<a href="#" id="force-suggestion-link">' + aiSuggestLocalize.clickToGenerate + '</a>');
                        }
                    
                    }).fail(function(jqXHR, textStatus, errorThrown) {
                        console.error('Falha na solicitação AJAX:', textStatus, errorThrown);
                        $('#ai-suggest-suggestions').html('<a href="#" id="force-suggestion-link">' + aiSuggestLocalize.clickToGenerate + '</a>');
                    }).always(function() {
                        isRequestPending = false;
                        if (isInitialLoad) {
                            interactionCount = 0;
                        }
                    });

                    if (isInitialLoad) {
                        $('#ai-suggest-suggestions').text(aiSuggestLocalize.generatingSuggestions);
                    }
                });
            }
        } catch (error) {
            console.error(aiSuggestLocalize.unknownError, error.message);
            $('#ai-suggest-suggestions').html('<a href="#" id="force-suggestion-link">' + aiSuggestLocalize.clickToGenerate + '</a>');
        }
    
    // Event handler for input changes in the editor, increasing interaction count
    $(document).on('input change', '.editor-post-title__input, .block-editor-rich-text__editable', function() {
        interactionCount++; // Incrementa o contador aqui
        getSuggestions();
    });

    // Event handler for the 'Save' button click
    $(document).on('click', '.ai-suggest-save-btn', function() {
        var $row = $(this).closest('.ai-suggest-row');
        var title = $row.find('.titulo-post').text().trim();
        var description = $row.find('.conteudo-post').text().trim();

        var dataToSend = {
            action: 'ai_suggest_save_draft',
            title: title,
            content: description,
            nonce: aiSuggestAjax.nonce
        };

        $.post(aiSuggestAjax.ajax_url, dataToSend, function(response) {
            if (response.success) {
                $(this).text(aiSuggestLocalize.undo).css('background-color', 'grey');
                $(this).removeClass('ai-suggest-save-btn').addClass('ai-suggest-undo-btn');
                $(this).data('draftId', response.data.draftId);
                showTemporaryMessage(aiSuggestLocalize.draftSaved);
            } else {
                alert(aiSuggestLocalize.errorSavingDraft + response.data);
            }
        }.bind(this));
    });

    // Event handler for the 'Undo' button click
    $(document).on('click', '.ai-suggest-undo-btn', function() {
        var draftId = $(this).data('draftId');
        var dataToSend = {
            action: 'ai_suggest_undo_draft',
            draftId: draftId,
            nonce: aiSuggestAjax.nonce
        };

        $.post(aiSuggestAjax.ajax_url, dataToSend, function(response) {
            if (response.success) {
                $(this).text(aiSuggestLocalize.save).css('background-color', 'green');
                $(this).removeClass('ai-suggest-undo-btn').addClass('ai-suggest-save-btn');
                $(this).removeData('draftId');
                showTemporaryMessage(aiSuggestLocalize.draftDeleted);
            } else {
                alert(aiSuggestLocalize.errorDeletingDraft + response.data);
            }
        }.bind(this));
    });

    // Initial call to generate suggestions
    getSuggestions(true).then(function() {
      // Post-suggestion generation code
    });

    // Utility function to clean the Gutenberg content from comments and HTML tags
    function cleanGutenbergContent(content) {
        content = content.replace(/<!--[\s\S]*?-->|<[^>]+>/g, '');
        return content;
    }

    // Function to display a temporary message
    function showTemporaryMessage(message, duration = 2000) {
        var messageBox = $('<div/>', {
          text: message,
          css: {
            position: 'fixed',
            top: '20px',
            left: '50%',
            transform: 'translateX(-50%)',
            zIndex: 1000,
            padding: '10px',
            backgroundColor: 'rgba(0,0,0,0.7)',
            color: 'white',
            borderRadius: '5px',
            boxShadow: '0 0 5px rgba(0,0,0,0.5)'
          }
        }).appendTo('body');
      
        setTimeout(function() {
          messageBox.fadeOut(function() {
            $(this).remove();
          });
        }, duration);
      }

    // Function to force the generation of suggestions
    function forceGenerateSuggestions() {
        isRequestPending = false;
        interactionCount = 100;
        getSuggestions(); // Chama a função para gerar as sugestões
      }

    // Handler para o botão Gerar
    $(document).on('click', '.ai-suggest-generate-btn', function() {
        isRequestPending = false;
        interactionCount = 100;
        getSuggestions();
    });

    // Event handler for 'Click to generate suggestions' link
    $(document).on('click', '#force-suggestion-link', function(e) {
        e.preventDefault();
        forceGenerateSuggestions();
    });

});