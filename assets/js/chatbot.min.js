// Shop Assistant JavaScript
(function($) {
    "use strict";
    
    // Translation helper
    var i18n = (typeof bootshas_ajax !== 'undefined' && bootshas_ajax.i18n) ? bootshas_ajax.i18n : {};
    function t(key, replacements) {
        var s = i18n[key] || key;
        if (replacements) {
            for (var k in replacements) {
                s = s.replace('{' + k + '}', replacements[k]);
            }
        }
        return s;
    }

    // Session ID for analytics
    var msaiSessionId = sessionStorage.getItem('msai_session_id');
    if (!msaiSessionId) {
        msaiSessionId = 'ms_' + Math.random().toString(36).substr(2, 9) + Date.now().toString(36);
        sessionStorage.setItem('msai_session_id', msaiSessionId);
    }

    // Wrap everything in try-catch to prevent other script errors from breaking chatbot
    try {

    let isRecording = false;
    let speechRecognition = null;
    let speechSilenceTimer = null;
    let voiceStopIntentional = false;
    let audioContext = null;
    let audioAnalyser = null;
    let audioStream = null;
    let audioLevelRAF = null;
    let listeningMsgShown = false;
    let mediaRecorder = null;
    let mediaChunks = [];
    let mediaStopTimer = null;
    let usingCloudSpeech = false;

    // Browser speech recognition is used only when the browser supports it.
    let isMobile = /Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent || '');
    let useWebSpeech = (function() {
        var ua = navigator.userAgent || '';
        // Must have "Chrome/" but NOT Brave/OPR/Edg/Firefox/Safari-only
        var isBrave = !!(navigator.brave && navigator.brave.isBrave);
        var isChrome = /Chrome\//.test(ua) && !/OPR\/|Edg\/|Firefox|SamsungBrowser/.test(ua) && !isBrave;
        var hasSR = !!(window.SpeechRecognition || window.webkitSpeechRecognition);
        return isChrome && hasSR;
    })();
    let comparisonMode = false;
    let selectedProducts = [];
    let chatHistory = [];
    let isRestoring = false;
    let selectedFilters = [];
    let activeFilterQuery = '';
    let lastDisplayedProducts = [];

    let pendingScrollPos = null;
    let pendingScrollToProduct = null;
    let voiceCountdownTimer = null;

    // Escape HTML special characters to prevent XSS
    function escHtml(str) {
        if (typeof str !== 'string') return '';
        return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function getImageCandidates(product) {
        if (!product) return [];
        var candidates = [];
        if (Array.isArray(product.image_candidates)) {
            candidates = candidates.concat(product.image_candidates);
        }
        if (Array.isArray(product.gallery)) {
            candidates = candidates.concat(product.gallery);
        }
        if (product.image) {
            candidates.push(product.image);
        }
        return candidates.filter(function(url, index, list) {
            return url && list.indexOf(url) === index;
        });
    }

    function applyImageFallback($img, candidates, altText) {
        candidates = (candidates || []).filter(function(url, index, list) {
            return url && list.indexOf(url) === index;
        });
        if (!candidates.length) return $img;

        var index = 0;
        function loadCandidate(nextIndex) {
            if (nextIndex >= candidates.length) {
                if (altText) $img.attr('alt', altText);
                $img.addClass('msai-image-broken');
                return;
            }

            index = nextIndex;
            $img.off('error.msaiFallback').on('error.msaiFallback', function() {
                loadCandidate(index + 1);
            });
            $img.attr('src', candidates[index]);
        }

        loadCandidate(0);
        return $img;
    }

    // Initialize chatbot
    function initChatbot() {
        // Floating button click handler
        $('#bootflow-shop-assist-floating-btn').on('click', function() {
            toggleModal();
        });

        // Minimize button click handler — toggles size (small ↔ full)
        $('#msai-minimize').on('click', function(e) {
            e.stopPropagation();
            toggleModalSize();
        });

        // Close button click handler
        $('#msai-close').on('click', function(e) {
            e.stopPropagation();
            closeModal();
        });

        // Chat form submission (triggered by smart button or Enter key)
        $('#msai-form').on('submit', function(e) {
            e.preventDefault();
            // Priority: comparison > filters > normal search
            if (selectedProducts.length >= 2) {
                showComparison();
                return;
            }
            if (selectedFilters.length > 0 && activeFilterQuery) {
                executeFilteredSearch();
                return;
            }
            const message = $('#msai-q').val().trim();
            if (message) {
                // Check for shipping voice command first
                if (handleShippingVoice(message)) return;
                // Check for add-to-cart intent before sending to server
                if (handleCartIntent(message)) return;
                sendMessage(message);
                $('#msai-q').val('');
                updateSmartButton();
            }
        });

        // Clear chat button handler
        $('#msai-clear').on('click', function(e) {
            e.stopPropagation();
            clearChatHistory();
        });

        // Smart button: 🎤 voice (empty input) | 🔍 search (has text) | ⚖️ compare | ⏹ stop
        $('#msai-smart-btn').on('click', function() {
            if (isRecording) {
                stopRecording();
                return;
            }
            if (selectedProducts.length >= 2) {
                $('#msai-form').trigger('submit');
                return;
            }
            var text = $('#msai-q').val().trim();
            if (text) {
                $('#msai-form').trigger('submit');
            } else {
                $(this).prop('disabled', true);
                startRecording();
                setTimeout(function() {
                    $('#msai-smart-btn').prop('disabled', false);
                }, 500);
            }
        });

        // Update smart button label when input changes
        $('#msai-q').on('input', function() {
            updateSmartButton();
        });

        // Initialize smart button state
        updateSmartButton();

        // Close chatbot when clicking outside (only for desktop in full-size mode)
        $(document).on('click', function(e) {
            var modal = $('#bootflow-shop-assist-chatbot');
            // If clicked element was removed from DOM (e.g. "Show More" button replaced),
            // it's not an outside click — ignore it
            if (!document.body.contains(e.target)) return;
            if ($(window).width() >= 768 && modal.hasClass('modal-open') && !$(e.target).closest('#bootflow-shop-assist-chatbot, #bootflow-shop-assist-floating-btn').length) {
                toggleModalSize(); // Shrink to small, don't close
            }
        });

        // Intercept all link clicks inside chat log — open in same window with chat state management
        // Exception: handoff buttons open in new tab (target=_blank)
        $(document).on('click', '#msai-log a', function(e) {
            if ($(this).hasClass('msai-handoff-btn')) return; // let browser handle _blank
            var href = $(this).attr('href');
            if (href && href !== '#' && !/^javascript:/i.test(href)) {
                e.preventDefault();
                navigateToLink(href);
            }
        });
    }

    // Toggle modal visibility
    function toggleModal() {
        const modal = $('#bootflow-shop-assist-chatbot');
        if (modal.is(':visible')) {
            closeModal();
        } else {
            openModal();
        }
    }

    // Open modal
    function openModal() {
        const modal = $('#bootflow-shop-assist-chatbot');
        modal.addClass('modal-open').show();
        updateToggleTitle();
        // Scroll to pending product card or restore scroll position
        if (pendingScrollToProduct) {
            setTimeout(function() {
                scrollToProductCard(pendingScrollToProduct);
                pendingScrollPos = null;
            }, 80);
        } else if (pendingScrollPos !== null) {
            setTimeout(function() {
                $('#msai-log').scrollTop(pendingScrollPos);
                pendingScrollPos = null;
            }, 50);
        }
        $('#msai-q').focus();
        // Hide floating button(s) when modal is open (defensive: handle duplicates)
        $('[id="bootflow-shop-assist-floating-btn"]').each(function() {
            try {
                $(this).addClass('hidden');
                this.style.setProperty('display', 'none', 'important');
            } catch (e) { /* ignore */ }
        });
    }

    // Close modal — hides window completely, shows floating button
    function closeModal() {
        const modal = $('#bootflow-shop-assist-chatbot');
        saveScrollOnly();
        modal.removeClass('modal-open').hide();
        // Show floating button when modal is fully closed
        $('[id="bootflow-shop-assist-floating-btn"]').each(function() {
            try {
                $(this).removeClass('hidden');
                this.style.setProperty('display', 'flex', 'important');
            } catch (e) { /* ignore */ }
        });
    }

    // Toggle modal size — switches between full-size and compact, window stays visible
    function toggleModalSize() {
        const modal = $('#bootflow-shop-assist-chatbot');
        modal.toggleClass('modal-open');
        updateToggleTitle();
        // Re-scroll to pending product when expanding
        if (modal.hasClass('modal-open') && pendingScrollToProduct) {
            setTimeout(function() {
                scrollToProductCard(pendingScrollToProduct);
            }, 80);
        }
    }

    // Update the minimize/maximize button tooltip
    function updateToggleTitle() {
        var btn = $('#msai-minimize');
        if ($('#bootflow-shop-assist-chatbot').hasClass('modal-open')) {
            btn.attr('title', t('btn_minimize_title'));
        } else {
            btn.attr('title', t('btn_maximize_title'));
        }
    }

    // Refresh nonce if stale (page-cache safe). Call cb() when done.
    function refreshNonce(cb) {
        $.post(bootshas_ajax.ajax_url, { action: 'bootshas_refresh_nonce' }, function(r) {
            if (r && r.success && r.data && r.data.nonce) bootshas_ajax.nonce = r.data.nonce;
            cb();
        }).fail(function() { cb(); });
    }

    // Send message to backend
    function sendMessage(message) {
        $('.msai-starter-questions').remove();
        addMessageToLog('user', message);

        // Build recent conversation history for context (last 10 text messages)
        var recentHistory = [];
        for (var hi = chatHistory.length - 1; hi >= 0 && recentHistory.length < 10; hi--) {
            if (chatHistory[hi].type === 'text' && chatHistory[hi].text) {
                recentHistory.unshift({ role: chatHistory[hi].sender === 'user' ? 'user' : 'assistant', content: chatHistory[hi].text });
            }
        }

        // Show loading indicator
        var loadingDiv = $('<div>').addClass('msai-message msai-bot msai-loading')
            .html('<span class="msai-dots"><span>.</span><span>.</span><span>.</span></span>');
        $('#msai-log').append(loadingDiv);
        scrollToLastUserMessage();

        function doSend(isRetry) {
            $.ajax({
                url: bootshas_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'bootshas_chat',
                    message: message,
                    nonce: bootshas_ajax.nonce,
                    chat_history: JSON.stringify(recentHistory),
                    session_id: msaiSessionId
                },
                success: function(response) {
                    if (!response.success && !isRetry && response.data && response.data.text &&
                        response.data.text.indexOf('security token') !== -1) {
                        // Nonce expired (page cache) — refresh and retry once
                        $.post(bootshas_ajax.ajax_url, { action: 'bootshas_refresh_nonce' }, function(r) {
                            if (r && r.success && r.data && r.data.nonce) {
                                bootshas_ajax.nonce = r.data.nonce;
                            }
                            doSend(true);
                        }).fail(function() { doSend(true); });
                        return;
                    }
                    loadingDiv.remove();
                    if (response.success) {
                        if (response.data.text) {
                            addMessageToLog('bot', response.data.text);
                        }
                        if (response.data.handoff) {
                            displayHandoffButtons(response.data.handoff, message);
                        }
                        if (response.data.questions) {
                            displayGiftQuestions(response.data.questions);
                        }
                        if (response.data.filters && response.data.original_query) {
                            displayFilterChips(response.data.filters, response.data.original_query);
                        }
                        if (response.data.products) {
                            displayProducts(response.data.products, response.data.mode);
                        }
                    } else {
                        addMessageToLog('bot', response.data.text || t('error_processing'));
                    }
                },
                error: function(xhr, status, error) {
                    loadingDiv.remove();
                    addMessageToLog('bot', t('error_server'));
                }
            });
        }
        doSend(false);
    }

    // Scroll so last user message is at top of visible area
    function scrollToLastUserMessage() {
        const log = $('#msai-log');
        const userMsgs = log.find('.msai-user');
        if (userMsgs.length > 0) {
            const lastUserMsg = userMsgs.last();
            const offset = lastUserMsg[0].offsetTop - log[0].offsetTop;
            log.scrollTop(offset);
        } else {
            log.scrollTop(log[0].scrollHeight);
        }
    }

    // Scroll so a specific element is at top of visible area (reliable with nested containers)
    function scrollToElement(element) {
        const log = document.getElementById('msai-log');
        const el = $(element)[0];
        if (el && log) {
            var logRect = log.getBoundingClientRect();
            var elRect = el.getBoundingClientRect();
            log.scrollTop += (elRect.top - logRect.top) - 10;
        }
    }

    // Scroll to a product card by ID with highlight effect; retries if card not ready
    function scrollToProductCard(productId, attempt) {
        attempt = attempt || 0;
        var card = $('#msai-log .msai-card[data-product-id="' + productId + '"]');
        if (card.length && card[0].offsetHeight > 0) {
            scrollToElement(card);
            card.css({'outline': '2px solid var(--msai-primary)', 'outline-offset': '2px', 'transition': 'outline 0.3s ease'});
            setTimeout(function(){ card.css({'outline': 'none', 'outline-offset': ''}); }, 2500);
            pendingScrollToProduct = null;
            return;
        }
        if (attempt < 4) {
            setTimeout(function(){ scrollToProductCard(productId, attempt + 1); }, 150);
        } else {
            // Final fallback: use saved scroll position
            if (pendingScrollPos !== null) {
                document.getElementById('msai-log').scrollTop = pendingScrollPos;
            }
            pendingScrollToProduct = null;
        }
    }

    // Lightbox for product images with gallery navigation
    function openLightbox(images, startIndex) {
        if (!images || !images.length) return;
        var idx = startIndex || 0;

        // Remove existing lightbox if any
        $('.msai-lightbox').remove();

        var overlay = $('<div>').addClass('msai-lightbox');
        var img = $('<img>').addClass('msai-lightbox-img').attr('src', images[idx]);
        var closeBtn = $('<button>').addClass('msai-lightbox-close').html('&times;');
        var counter = $('<div>').addClass('msai-lightbox-counter');

        function updateImage() {
            img.attr('src', images[idx]);
            counter.text(images.length > 1 ? (idx + 1) + ' / ' + images.length : '');
        }

        closeBtn.on('click', function(e) { e.stopPropagation(); overlay.remove(); });
        overlay.on('click', function(e) {
            if (e.target === overlay[0]) overlay.remove();
        });

        overlay.append(closeBtn).append(img).append(counter);

        if (images.length > 1) {
            var prevBtn = $('<button>').addClass('msai-lightbox-nav msai-lightbox-prev').html('&#8249;');
            var nextBtn = $('<button>').addClass('msai-lightbox-nav msai-lightbox-next').html('&#8250;');
            prevBtn.on('click', function(e) { e.stopPropagation(); idx = (idx - 1 + images.length) % images.length; updateImage(); });
            nextBtn.on('click', function(e) { e.stopPropagation(); idx = (idx + 1) % images.length; updateImage(); });
            overlay.append(prevBtn).append(nextBtn);
        }

        // Keyboard navigation
        $(document).on('keydown.msaiLightbox', function(e) {
            if (e.key === 'Escape') { overlay.remove(); $(document).off('keydown.msaiLightbox'); }
            if (e.key === 'ArrowLeft' && images.length > 1) { idx = (idx - 1 + images.length) % images.length; updateImage(); }
            if (e.key === 'ArrowRight' && images.length > 1) { idx = (idx + 1) % images.length; updateImage(); }
        });

        updateImage();
        $('body').append(overlay);
    }

    // Save scroll position (always)
    function saveScrollOnly() {
        try {
            var pos = $('#msai-log').scrollTop();
            sessionStorage.setItem('msai_scroll_pos', pos);
        } catch(e) { /* ignore */ }
    }

    // Save scroll position + set reopen flag (for product link clicks)
    function saveScrollPosition() {
        saveScrollOnly();
        try {
            sessionStorage.setItem('msai_reopen', '1');
        } catch(e) { /* ignore */ }
    }

    // Navigate to a link: save scroll, minimize/close chatbot, then go
    function navigateToLink(url, clickedProductId) {
        saveScrollOnly();
        try {
            sessionStorage.setItem('msai_reopen', 'link');
            if (clickedProductId) {
                sessionStorage.setItem('msai_clicked_product', clickedProductId);
            }
        } catch(e) {}
        window.location.href = url;
    }

    // Session storage helpers
    function saveToHistory(entry) {
        if (isRestoring) return;
        chatHistory.push(entry);
        try {
            sessionStorage.setItem('msai_chat_history', JSON.stringify(chatHistory));
        } catch(e) { /* quota exceeded */ }
    }

    function clearChatHistory() {
        chatHistory = [];
        sessionStorage.removeItem('msai_chat_history');
        $('#msai-log').empty();
        selectedProducts = [];
        updateComparisonButton();
        showStarterQuestions();
    }

    function restoreChatHistory() {
        try {
            var saved = sessionStorage.getItem('msai_chat_history');
            if (!saved) return;
            var entries = JSON.parse(saved);
            if (!Array.isArray(entries) || entries.length === 0) return;
            chatHistory = [];
            isRestoring = true;
            entries.forEach(function(entry) {
                switch(entry.type) {
                    case 'text':
                        addMessageToLog(entry.sender, entry.text);
                        break;
                    case 'products':
                        displayProducts(entry.products, entry.mode);
                        break;
                    case 'filters':
                        displayFilterChips(entry.filters, entry.originalQuery);
                        break;
                    case 'questions':
                        displayGiftQuestions(entry.questions);
                        break;
                    case 'comparison':
                        displayComparisonTable(entry.comparison);
                        break;
                    case 'handoff':
                        displayHandoffButtons(entry.handoff, entry.userQuery);
                        break;
                    case 'details':
                        var log = $('#msai-log');
                        var d = $('<div>').addClass('msai-message msai-bot msai-product-details').html(entry.html);
                        log.append(d);
                        break;
                }
            });
            isRestoring = false;
            chatHistory = entries;
            // Save scroll position to apply when modal becomes visible
            var savedScroll = sessionStorage.getItem('msai_scroll_pos');
            if (savedScroll !== null) {
                pendingScrollPos = parseInt(savedScroll, 10);
            }
        } catch(e) {
            console.error('Failed to restore chat history:', e);
            chatHistory = [];
        }
    }

    // Show starter questions when chat is empty
    function showStarterQuestions() {
        var log = $('#msai-log');
        log.empty();
        // GDPR notice — custom from admin or default from translations
        var gdprText = bootshas_ajax.gdpr_notice || t('gdpr_notice');
        if (gdprText) {
            var gdpr = $('<div>').addClass('msai-message msai-bot msai-gdpr-notice')
                .css({fontSize: '12px', opacity: 0.75}).text(gdprText);
            log.append(gdpr);
        }
        // Welcome message — custom from white-label or default from translations
        var welcomeText = bootshas_ajax.wl_welcome || t('welcome_message');
        var welcome = $('<div>').addClass('msai-message msai-bot').text(welcomeText);
        log.append(welcome);
        // Starter questions — loaded from admin config
        var starters = bootshas_ajax.starter_questions || [];
        if (starters.length > 0) {
            var container = $('<div>').addClass('msai-starter-questions');
            starters.forEach(function(sq, idx) {
                var btn = $('<button>').addClass('msai-starter-btn').text(sq.question);
                btn.on('click', function() {
                    $('.msai-starter-questions').remove();
                    addMessageToLog('user', sq.question);

                    // If type is text with no search/ai, show directly
                    // Otherwise call backend
                    var loadingDiv = $('<div>').addClass('msai-message msai-bot msai-loading')
                        .html('<span class="msai-dots"><span>.</span><span>.</span><span>.</span></span>');
                    $('#msai-log').append(loadingDiv);
                    scrollToLastUserMessage();

                    function doStarterCall(isRetry) {
                        $.ajax({
                            url: bootshas_ajax.ajax_url,
                            type: 'POST',
                            data: {
                                action: 'bootshas_starter_answer',
                                index: idx,
                                nonce: bootshas_ajax.nonce
                            },
                            success: function(response) {
                                if (!response.success && !isRetry && response.data && response.data.text &&
                                    response.data.text.indexOf('security token') !== -1) {
                                    refreshNonce(function() { doStarterCall(true); });
                                    return;
                                }
                                loadingDiv.remove();
                                if (response.success) {
                                    if (response.data.text) {
                                        addMessageToLog('bot', response.data.text);
                                    }
                                    if (response.data.products && response.data.products.length > 0) {
                                        displayProducts(response.data.products, response.data.mode || 'search');
                                    }
                                } else {
                                    addMessageToLog('bot', response.data.text || t('error_processing'));
                                }
                            },
                            error: function() {
                                loadingDiv.remove();
                                addMessageToLog('bot', t('error_server'));
                            }
                        });
                    }
                    doStarterCall(false);
                });
                container.append(btn);
            });
            log.append(container);
        }
    }

    // Add message to chat log
    function addMessageToLog(sender, message, noScroll) {
        const log = $('#msai-log');
        const messageDiv = $('<div>').addClass('msai-message msai-' + sender);
        if (sender === 'user') {
            var prev = log.children().last();
            if (prev.hasClass('msai-products') || prev.hasClass('msai-load-more')) {
                messageDiv.addClass('msai-user-after-products');
            }
        }
        if (sender === 'bot') {
            // Allow safe HTML tags from bot responses (<a>, <br>, <b>, <i>, <strong>, <em>, <p>, <ul>, <li>)
            var safe = sanitizeBotHtml(message);
            // Also linkify plain URLs that aren't already inside an <a> tag
            safe = safe.replace(/(?:<a[^>]*>.*?<\/a>)|((https?:\/\/[^\s<]+))/g, function(match, url) {
                return url ? '<a href="' + url + '" rel="noopener">' + url + '</a>' : match;
            });
            messageDiv.html(safe);
        } else {
            // User messages — always escape everything
            var safe = $('<span>').text(message).html();
            messageDiv.html(safe);
        }
        log.append(messageDiv);
        saveToHistory({type: 'text', sender: sender, text: message});
        if (!noScroll) {
            if (sender === 'bot') {
                log.scrollTop(log[0].scrollHeight);
            } else {
                scrollToLastUserMessage();
            }
        } else if (sender === 'bot') {
            // Voice/system bot notices often pass noScroll=true; keep latest message visible.
            log.scrollTop(log[0].scrollHeight);
        }
    }

    // Sanitize bot HTML — keep only safe tags, strip everything else
    function sanitizeBotHtml(html) {
        // Allowed tags: a, br, b, i, strong, em, p, ul, ol, li, span
        // For <a>: allow href, target, style, rel attributes only
        // For <span>: allow style attribute only
        var tmp = document.createElement('div');
        tmp.innerHTML = html;
        function clean(node) {
            var children = Array.from(node.childNodes);
            for (var i = 0; i < children.length; i++) {
                var c = children[i];
                if (c.nodeType === 3) continue; // text node — keep
                if (c.nodeType === 1) { // element
                    var tag = c.tagName.toLowerCase();
                    var allowed = ['a','br','b','i','strong','em','p','ul','ol','li','span'];
                    if (allowed.indexOf(tag) === -1) {
                        // Replace disallowed tag with its text content
                        var text = document.createTextNode(c.textContent);
                        node.replaceChild(text, c);
                    } else {
                        // Strip disallowed attributes
                        var attrs = Array.from(c.attributes);
                        for (var j = 0; j < attrs.length; j++) {
                            var name = attrs[j].name.toLowerCase();
                            if (tag === 'a' && ['href','style','rel'].indexOf(name) !== -1) continue;
                            if (tag === 'span' && name === 'style') continue;
                            c.removeAttribute(attrs[j].name);
                        }
                        // Sanitize href — only allow http/https/mailto
                        if (tag === 'a' && c.hasAttribute('href')) {
                            var href = c.getAttribute('href');
                            if (!/^(https?:|mailto:)/i.test(href)) {
                                c.removeAttribute('href');
                            }
                        }
                        clean(c);
                    }
                } else {
                    node.removeChild(c); // remove comments etc
                }
            }
        }
        clean(tmp);
        return tmp.innerHTML;
    }

    // Display handoff contact buttons below automated response
    function displayHandoffButtons(handoff, userQuery) {
        // Strict mode: disable external handoff button rendering.
        return;
    }

    // Show product details
    function showProductDetails(productId) {
        // Show loading dots
        var loadingDiv = $('<div>').addClass('msai-message msai-bot msai-loading')
            .html('<span class="msai-dots"><span>.</span><span>.</span><span>.</span></span>');
        $('#msai-log').append(loadingDiv);
        scrollToLastUserMessage();

        $.ajax({
            url: bootshas_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'bootshas_get_product_details',
                product_id: productId,
                nonce: bootshas_ajax.nonce
            },
            success: function(response) {
                loadingDiv.remove();
                if (response.success && response.data.description) {
                    const log = $('#msai-log');
                    const detailsDiv = $('<div>').addClass('msai-message msai-bot msai-product-details');

                    // Header row: image + name
                    var header = $('<div>').addClass('msai-details-header');
                    if (response.data.image) {
                        var img = $('<img>')
                            .attr('alt', response.data.product_name || '')
                            .addClass('msai-details-img')
                            .on('click', function() {
                                openLightbox(getImageCandidates(response.data), 0);
                            });
                        applyImageFallback(img, getImageCandidates(response.data), response.data.product_name || '');
                        header.append(img);
                    }
                    var nameLink = $('<a>').addClass('msai-details-name')
                        .text(response.data.product_name || '')
                        .attr('href', response.data.permalink || '#');
                    header.append(nameLink);
                    detailsDiv.append(header);

                    // Description text
                    var descDiv = $('<div>').addClass('msai-details-text').text(response.data.description);
                    detailsDiv.append(descDiv);

                    log.append(detailsDiv);
                    saveToHistory({type: 'details', html: detailsDiv[0].outerHTML});
                    scrollToElement(detailsDiv);
                } else {
                    addMessageToLog('bot', t('no_description'));
                }
            },
            error: function() {
                loadingDiv.remove();
                addMessageToLog('bot', t('error_loading'));
            }
        });
    }

    // Toggle product for comparison
    function toggleProductForComparison(product, cardElement) {
        const productId = product.id;
        const existingIndex = selectedProducts.findIndex(p => p.id === productId);

        if (existingIndex >= 0) {
            // Remove from comparison
            selectedProducts.splice(existingIndex, 1);
            cardElement.removeClass('msai-card-selected msai-card-ready');
        } else {
            // Add to comparison (max 3 products)
            if (selectedProducts.length >= 3) {
                addMessageToLog('bot', t('max_compare'));
                return;
            }
            selectedProducts.push(product);
            
            // First product = yellow, 2+ = green
            if (selectedProducts.length === 1) {
                cardElement.addClass('msai-card-selected'); // yellow
            } else {
                // Make all cards green when 2+
                $('.msai-card-selected').removeClass('msai-card-selected').addClass('msai-card-ready');
                cardElement.addClass('msai-card-ready'); // green
            }
        }

        // Update comparison button visibility
        updateComparisonButton();
    }

    // Update comparison button — reuse Meklēt button
    function updateComparisonButton() {
        updateSearchButton();
    }

    // Show comparison table
    function showComparison() {
        if (selectedProducts.length < 2) {
            addMessageToLog('bot', t('min_compare'));
            return;
        }

        addMessageToLog('bot', t('preparing_compare'));

        const productIds = selectedProducts.map(p => p.id);

        // Show loading dots
        var loadingDiv = $('<div>').addClass('msai-message msai-bot msai-loading')
            .html('<span class="msai-dots"><span>.</span><span>.</span><span>.</span></span>');
        $('#msai-log').append(loadingDiv);
        scrollToLastUserMessage();

        $.ajax({
            url: bootshas_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'bootshas_compare_products',
                product_ids: productIds,
                nonce: bootshas_ajax.nonce,
                session_id: msaiSessionId
            },
            success: function(response) {
                loadingDiv.remove();
                if (response.success && response.data.comparison) {
                    displayComparisonTable(response.data.comparison);
                    // Reset comparison mode
                    selectedProducts = [];
                    $('.msai-card-selected, .msai-card-ready').removeClass('msai-card-selected msai-card-ready');
                    updateComparisonButton();
                } else {
                    addMessageToLog('bot', t('compare_failed'));
                }
            },
            error: function() {
                loadingDiv.remove();
                addMessageToLog('bot', t('compare_error'));
            }
        });
    }

    // AJAX add to cart (stays on page, changes button state)
    function addToCartAjax(productId, btn) {
        btn.prop('disabled', true).text(t('btn_adding'));
        $.post(bootshas_ajax.ajax_url, {
            action: 'bootshas_add_to_cart',
            nonce: bootshas_ajax.nonce,
            product_id: productId,
            quantity: 1,
            session_id: msaiSessionId
        }).done(function(response) {
            if (response.success) {
                btn.text(t('btn_added'))
                   .css({'background-color': '#1a6b2a', 'cursor': 'default'})
                   .off('click')
                   .prop('disabled', false);
                $(document.body).trigger('wc_fragment_refresh');
            } else {
                var errMsg = (response.data && response.data.message) ? response.data.message : t('btn_error');
                btn.text(t('btn_error')).css({'background-color': '#dc3545'}).prop('disabled', false);
                addMessageToLog('bot', '⚠️ ' + errMsg, true);
                setTimeout(function() { btn.text(t('btn_add_to_cart')).css({'background-color': ''}); }, 3000);
            }
        }).fail(function() {
            btn.text(t('btn_error_retry'))
               .css({'background-color': '#dc3545'})
               .prop('disabled', false);
            setTimeout(function() {
                btn.text(t('btn_add_to_cart'))
                   .css({'background-color': ''});
            }, 2000);
        });
    }

    // Display comparison table
    function displayComparisonTable(comparison) {
        const log = $('#msai-log');
        const tableDiv = $('<div>').addClass('msai-comparison-table');
        
        const colCount = comparison.products.length + 1;
        const colWidth = Math.floor(100 / colCount) + '%';
        
        const table = $('<table>').css({
            'width': '100%',
            'border-collapse': 'collapse',
            'margin': '10px 0',
            'table-layout': 'fixed'
        });

        // Header row with product names
        const headerRow = $('<tr>');
        headerRow.append($('<th>').text(t('label_product')).css({
            'border': '1px solid #ddd',
            'padding': '8px',
            'background': '#f2f2f2',
            'text-align': 'left',
            'width': colWidth,
            'word-wrap': 'break-word'
        }));
        
        comparison.products.forEach(function(product) {
            var th = $('<th>').html('<a href="' + escHtml(product.permalink) + '">' + escHtml(product.name) + '</a>').css({
                'border': '1px solid #ddd',
                'padding': '8px',
                'background': '#f2f2f2',
                'text-align': 'left',
                'width': colWidth,
                'word-wrap': 'break-word'
            });
            headerRow.append(th);
        });
        table.append(headerRow);

        // Image row
        const imgRow = $('<tr>');
        imgRow.append($('<td>').append($('<strong>').text(t('label_image'))).css({
            'border': '1px solid #ddd',
            'padding': '8px'
        }));
        comparison.products.forEach(function(product) {
            var td = $('<td>').css({
                'border': '1px solid #ddd',
                'padding': '8px',
                'text-align': 'center'
            });
            const imageCandidates = getImageCandidates(product);
            if (imageCandidates.length > 0) {
                var img = $('<img>')
                    .attr('alt', product.name)
                    .addClass('msai-compare-img')
                    .on('click', function() {
                        openLightbox(imageCandidates, 0);
                    });
                applyImageFallback(img, imageCandidates, product.name);
                td.append(img);
            } else {
                td.text('—');
            }
            imgRow.append(td);
        });
        table.append(imgRow);

        // Attribute rows
        comparison.attributes.forEach(function(attr) {
            const row = $('<tr>');
            row.append($('<td>').append($('<strong>').text(attr.label)).css({
                'border': '1px solid #ddd',
                'padding': '8px'
            }));
            
            attr.values.forEach(function(value) {
                var td = $('<td>').css({
                    'border': '1px solid #ddd',
                    'padding': '8px'
                });
                if (attr.is_description) {
                    var wrapper = $('<div>').css({
                        'max-height': '180px',
                        'overflow-y': 'auto',
                        'line-height': '1.4',
                        'font-size': '13px'
                    }).html(sanitizeBotHtml(value || '—'));
                    td.append(wrapper);
                } else {
                    td.text(value || '—');
                }
                row.append(td);
            });
            table.append(row);
        });

        // Add to cart row inside table
        const cartRow = $('<tr>');
        cartRow.append($('<td>').append($('<strong>').text(t('label_order'))).css({
            'border': '1px solid #ddd',
            'padding': '8px'
        }));
        comparison.products.forEach(function(product) {
            const td = $('<td>').css({
                'border': '1px solid #ddd',
                'padding': '8px',
                'text-align': 'center'
            });
            if (product.stock_status === 'instock' && product.add_to_cart_url) {
                const btn = $('<button>')
                    .addClass('msai-btn msai-btn-cart')
                    .text(t('btn_add_to_cart'))
                    .css({'width': '100%'})
                    .on('click', function() {
                        addToCartAjax(product.id, $(this));
                    });
                td.append(btn);
            } else {
                td.append($('<span>').css({'color': '#dc3545', 'font-weight': 'bold'}).text(t('out_of_stock')));
            }
            cartRow.append(td);
        });
        table.append(cartRow);

        tableDiv.append(table);
        log.append(tableDiv);
        saveToHistory({type: 'comparison', comparison: comparison});
        scrollToElement(tableDiv);
    }

    // Display products with infinite scroll
    var PRODUCTS_PER_PAGE = 21;

    function displayProducts(products, mode = 'search') {
        const log = $('#msai-log');
        const productsDiv = $('<div>').addClass('msai-products msai-grid');
        const allProducts = products;
        lastDisplayedProducts = products;
        productsDiv.data('products', Array.isArray(products) ? products.slice() : []);
        productsDiv.data('sortKey', 'relevance');
        var shown = 0;

        function createCard(product) {
            const card = $('<div>').addClass('msai-card').attr('data-product-id', product.id);
            
            // Make image clickable - check if any image candidates exist
            const imageCandidates = getImageCandidates(product);
            if (imageCandidates.length > 0) {
                const imgWrap = $('<div>').addClass('msai-card-img-wrap');
                const img = $('<img>')
                    .attr('alt', product.title)
                    .css('cursor', 'pointer')
                    .on('click', function() {
                        if (product.permalink) {
                            navigateToLink(product.permalink, product.id);
                        }
                    });
                applyImageFallback(img, imageCandidates, product.title);
                imgWrap.append(img);
                card.append(imgWrap);
            }
            
            // Make title clickable
            const title = $('<h4>')
                .text(product.title)
                .css('cursor', 'pointer')
                .on('click', function() {
                    if (product.permalink) {
                        navigateToLink(product.permalink, product.id);
                    }
                });
            card.append(title);
            
            var priceDisplay = product.price ? (product.price + ' ' + decodeHtmlEntities(product.currency || '€')) : '';
            if (priceDisplay) card.append($('<p>').text(priceDisplay));

            // Button container
            const btnContainer = $('<div>').addClass('msai-btn-container');

            // "Sīkāk" button
            const detailsBtn = $('<button>')
                .addClass('msai-btn msai-btn-details')
                .text(t('btn_details'))
                .on('click', function() {
                    showProductDetails(product.id);
                });
            btnContainer.append(detailsBtn);

            // "Salīdzināt" button
            const compareBtn = $('<button>')
                .addClass('msai-btn msai-btn-compare')
                .text(t('btn_compare'))
                .on('click', function() {
                    toggleProductForComparison(product, card);
                });
            btnContainer.append(compareBtn);

            // "Pievienot grozam" button
            if (product.stock_status === 'instock') {
                const addToCartBtn = $('<button>')
                    .addClass('msai-btn msai-btn-cart')
                    .text(t('btn_add_to_cart'))
                    .on('click', function() {
                        addToCartAjax(product.id, $(this));
                    });
                btnContainer.append(addToCartBtn);
            } else {
                btnContainer.append($('<p>').addClass('out-of-stock').text(t('out_of_stock')));
            }

            card.append(btnContainer);
            return card;
        }

        function showMore() {
            var start = shown;
            var end = Math.min(shown + PRODUCTS_PER_PAGE, allProducts.length);
            var firstNew = null;
            for (var i = shown; i < end; i++) {
                var card = createCard(allProducts[i]);
                card.css('animation-delay', ((i - shown) * 0.04) + 's');
                productsDiv.append(card);
                if (!firstNew) firstNew = card;
            }
            shown = end;
            updateLoadMoreButton();
            // Scroll to first newly loaded card
            if (firstNew && start > 0) {
                scrollToElement(firstNew);
            }
        }

        // "Show more" button
        var loadMoreDiv = null;
        function updateLoadMoreButton() {
            if (loadMoreDiv) loadMoreDiv.remove();
            if (shown < allProducts.length) {
                var remaining = allProducts.length - shown;
                loadMoreDiv = $('<div>').addClass('msai-load-more');
                var btn = $('<button>').addClass('msai-btn msai-btn-load-more')
                    .text(t('show_more') + ' (' + shown + '/' + allProducts.length + ')')
                    .on('click', function() {
                        showMore();
                    });
                loadMoreDiv.append(btn);
                productsDiv.after(loadMoreDiv);
            }
        }

        // Append grid to DOM first, then load first batch
        // (updateLoadMoreButton needs productsDiv in DOM for .after() to work)
        log.append(productsDiv);
        showMore();
        saveToHistory({type: 'products', products: products, mode: mode});
        scrollToLastUserMessage();

        // Show sorting chips if more than 1 product
        if (products.length > 1) {
            displaySortChips(productsDiv);
        }
    }

    // ---- Sorting chips ----
    function displaySortChips(productsDiv) {
        // Remove any existing sort bar for this products grid
        productsDiv.prev('.msai-sort-chips').remove();
        var localSortKey = productsDiv.data('sortKey') || 'relevance';

        var container = $('<div>').addClass('msai-message msai-bot msai-sort-chips');
        container.append($('<div>').addClass('msai-sort-label').text(t('sort_label')));
        var row = $('<div>').addClass('msai-chips-row');

        var sortOptions = [
            { key: 'relevance',  label: t('sort_relevance') },
            { key: 'price_asc',  label: t('sort_price_asc') },
            { key: 'price_desc', label: t('sort_price_desc') },
            { key: 'rating',     label: t('sort_rating') },
            { key: 'newest',     label: t('sort_newest') }
        ];

        sortOptions.forEach(function(opt) {
            var btn = $('<button>')
                .addClass('msai-filter-chip msai-sort-chip')
                .attr('data-sort', opt.key)
                .text(opt.label)
                .on('click', function() {
                    if ((productsDiv.data('sortKey') || 'relevance') === opt.key) return;
                    productsDiv.data('sortKey', opt.key);
                    // Update selected state
                    container.find('.msai-sort-chip').removeClass('msai-chip-selected');
                    $(this).addClass('msai-chip-selected');
                    // Sort and re-render
                    applySorting(productsDiv);
                });
            if (opt.key === localSortKey) {
                btn.addClass('msai-chip-selected');
            }
            row.append(btn);
        });

        container.append(row);
        productsDiv.before(container);
    }

    function applySorting(productsDiv) {
        var sourceProducts = productsDiv.data('products') || [];
        var activeSortKey = productsDiv.data('sortKey') || 'relevance';
        var sorted = sourceProducts.slice();

        switch (activeSortKey) {
            case 'price_asc':
                sorted.sort(function(a, b) { return parseFloat(a.price) - parseFloat(b.price); });
                break;
            case 'price_desc':
                sorted.sort(function(a, b) { return parseFloat(b.price) - parseFloat(a.price); });
                break;
            case 'rating':
                sorted.sort(function(a, b) { return parseFloat(b.rating || 0) - parseFloat(a.rating || 0); });
                break;
            case 'newest':
                sorted.sort(function(a, b) {
                    return (b.date || '').localeCompare(a.date || '');
                });
                break;
            // 'relevance' — original order, no sort needed
        }

        // Re-render cards in the existing grid
        productsDiv.empty();
        // Also remove old load-more button
        productsDiv.next('.msai-load-more').remove();

        var shown = 0;
        var allProducts = sorted;

        function createSortedCard(product) {
            // Reuse the same card creation from displayProducts
            var card = $('<div>').addClass('msai-card').attr('data-product-id', product.id);
            if (product.image) {
                var imgWrap = $('<div>').addClass('msai-card-img-wrap');
                var img = $('<img>').attr('alt', product.title)
                    .css('cursor', 'pointer')
                    .on('click', function() { if (product.permalink) navigateToLink(product.permalink); });
                applyImageFallback(img, getImageCandidates(product), product.title);
                imgWrap.append(img);
                card.append(imgWrap);
            }
            var title = $('<h4>').text(product.title).css('cursor', 'pointer')
                .on('click', function() { if (product.permalink) navigateToLink(product.permalink); });
            card.append(title);
            var priceDisplay = product.price ? (product.price + ' ' + decodeHtmlEntities(product.currency || '€')) : '';
            if (priceDisplay) card.append($('<p>').text(priceDisplay));
            var btnContainer = $('<div>').addClass('msai-btn-container');
            btnContainer.append($('<button>').addClass('msai-btn msai-btn-details').text(t('btn_details'))
                .on('click', function() { showProductDetails(product.id); }));
            btnContainer.append($('<button>').addClass('msai-btn msai-btn-compare').text(t('btn_compare'))
                .on('click', function() { toggleProductForComparison(product, card); }));
            if (product.stock_status === 'instock') {
                btnContainer.append($('<button>').addClass('msai-btn msai-btn-cart').text(t('btn_add_to_cart'))
                    .on('click', function() { addToCartAjax(product.id, $(this)); }));
            } else {
                btnContainer.append($('<p>').addClass('out-of-stock').text(t('out_of_stock')));
            }
            card.append(btnContainer);
            return card;
        }

        function showMoreSorted() {
            var end = Math.min(shown + PRODUCTS_PER_PAGE, allProducts.length);
            for (var i = shown; i < end; i++) {
                var card = createSortedCard(allProducts[i]);
                card.css('animation-delay', ((i - shown) * 0.04) + 's');
                productsDiv.append(card);
            }
            shown = end;
            updateLoadMore();
        }

        var loadMoreDiv = null;
        function updateLoadMore() {
            if (loadMoreDiv) loadMoreDiv.remove();
            if (shown < allProducts.length) {
                loadMoreDiv = $('<div>').addClass('msai-load-more');
                var btn = $('<button>').addClass('msai-btn msai-btn-load-more')
                    .text(t('show_more') + ' (' + shown + '/' + allProducts.length + ')')
                    .on('click', function() { showMoreSorted(); });
                loadMoreDiv.append(btn);
                productsDiv.after(loadMoreDiv);
            }
        }

        showMoreSorted();
        // Scroll to the sort chips bar so it stays visible after re-sort
        var sortBar = productsDiv.prev('.msai-sort-chips');
        scrollToElement(sortBar.length ? sortBar : productsDiv);
    }

    // Display filter chips — multi-select toggle with dynamic compatibility
    var currentChipsData = []; // store chip data for compatibility checks

    function displayFilterChips(filters, originalQuery) {
        if (!Array.isArray(filters) || filters.length === 0) return;
        // Reset filter state
        selectedFilters = [];
        currentChipsData = filters;
        activeFilterQuery = originalQuery;
        updateSearchButton();

        const log = $('#msai-log');
        const container = $('<div>').addClass('msai-message msai-bot msai-filter-chips')
            .attr('data-original-query', originalQuery);
        container.append($('<div>').addClass('msai-filter-label').text(t('narrow_search')));
        const row = $('<div>').addClass('msai-chips-row');

        filters.forEach(function(chip, idx) {
            const btn = $('<button>')
                .addClass('msai-filter-chip')
                .attr('data-filter-idx', idx)
                .text(chip.label)
                .on('click', function() {
                    if ($(this).hasClass('msai-chip-disabled')) return;
                    // Switch context to this chip group if different
                    var containerQuery = container.attr('data-original-query');
                    if (activeFilterQuery !== containerQuery) {
                        // Deselect chips from other filter groups
                        $('.msai-filter-chips').not(container).find('.msai-chip-selected').removeClass('msai-chip-selected');
                        selectedFilters = [];
                        activeFilterQuery = containerQuery;
                        currentChipsData = filters;
                        updateSearchButton();
                    }
                    toggleFilterChip(chip, $(this));
                });
            row.append(btn);
        });

        container.append(row);
        log.append(container);
        saveToHistory({type: 'filters', filters: filters, originalQuery: originalQuery});
        scrollToLastUserMessage();
    }

    function toggleFilterChip(chip, btnEl) {
        var chipKey = JSON.stringify(chip);
        var idx = -1;
        for (var i = 0; i < selectedFilters.length; i++) {
            if (JSON.stringify(selectedFilters[i]) === chipKey) { idx = i; break; }
        }
        if (idx >= 0) {
            selectedFilters.splice(idx, 1);
            btnEl.removeClass('msai-chip-selected');
        } else {
            selectedFilters.push(chip);
            btnEl.addClass('msai-chip-selected');
        }
        updateSearchButton();
        updateChipCompatibility();
    }

    // Compute intersection of match arrays
    function intersectMatches(arrays) {
        if (arrays.length === 0) return [];
        var result = arrays[0].slice();
        for (var i = 1; i < arrays.length; i++) {
            var set = {};
            arrays[i].forEach(function(v) { set[v] = true; });
            result = result.filter(function(v) { return set[v]; });
        }
        return result;
    }

    // Update chip availability based on selected chips
    function updateChipCompatibility() {
        if (!currentChipsData.length) return;

        // Get match arrays of all selected chips
        var selectedMatchArrays = [];
        selectedFilters.forEach(function(sel) {
            if (sel.matches && sel.matches.length > 0) {
                selectedMatchArrays.push(sel.matches);
            }
        });

        // Current active product set (intersection of all selected)
        var activeSet = selectedMatchArrays.length > 0
            ? intersectMatches(selectedMatchArrays)
            : null; // null = nothing selected, all available

        $('.msai-filter-chip').each(function() {
            var idx = parseInt($(this).attr('data-filter-idx'));
            var chip = currentChipsData[idx];
            if (!chip) return;

            // Skip already selected chips
            if ($(this).hasClass('msai-chip-selected')) {
                $(this).removeClass('msai-chip-disabled msai-chip-available');
                return;
            }

            if (activeSet === null) {
                // Nothing selected — all chips neutral (remove states)
                $(this).removeClass('msai-chip-disabled msai-chip-available');
                return;
            }

            // Check if this chip has any overlap with current active set
            var chipMatches = chip.matches || [];
            var activeSetMap = {};
            activeSet.forEach(function(v) { activeSetMap[v] = true; });
            var hasOverlap = chipMatches.some(function(v) { return activeSetMap[v]; });

            if (hasOverlap) {
                $(this).removeClass('msai-chip-disabled').addClass('msai-chip-available');
            } else {
                $(this).removeClass('msai-chip-available').addClass('msai-chip-disabled');
            }
        });
    }

    function updateSearchButton() {
        updateSmartButton();
    }

    function updateSmartButton() {
        var btn = $('#msai-smart-btn');
        if (isRecording) return; // Don't change during recording
        // Priority: comparison > filters > text present > voice
        if (selectedProducts.length >= 2) {
            btn.text(t('compare_n', {n: selectedProducts.length})).addClass('msai-btn-filter-active').removeClass('msai-smart-voice msai-recording');
        } else if (selectedProducts.length === 1) {
            btn.text(t('search_dot_1')).removeClass('msai-btn-filter-active msai-smart-voice msai-recording');
        } else if (selectedFilters.length > 0) {
            btn.text(t('search_n', {n: selectedFilters.length})).addClass('msai-btn-filter-active').removeClass('msai-smart-voice msai-recording');
        } else if ($('#msai-q').val().trim()) {
            btn.text(t('btn_search')).removeClass('msai-btn-filter-active msai-smart-voice msai-recording');
        } else {
            btn.text(t('btn_voice')).removeClass('msai-btn-filter-active msai-recording').addClass('msai-smart-voice');
        }
    }

    function executeFilteredSearch() {
        var filterData = {};
        var labels = [];
        selectedFilters.forEach(function(chip) {
            if (chip.max_price) filterData.filter_max_price = chip.max_price;
            if (chip.min_price) filterData.filter_min_price = chip.min_price;
            if (chip.keyword) {
                // Combine multiple keywords
                if (filterData.filter_keyword) {
                    filterData.filter_keyword += ' ' + chip.keyword;
                } else {
                    filterData.filter_keyword = chip.keyword;
                }
            }
            labels.push(chip.label);
        });

        addMessageToLog('user', activeFilterQuery + ' → ' + labels.join(', '));

        // Reset state
        selectedFilters = [];
        updateSearchButton();
        $('.msai-chip-selected').removeClass('msai-chip-selected');

        // Show loading dots
        var loadingDiv = $('<div>').addClass('msai-message msai-bot msai-loading')
            .html('<span class="msai-dots"><span>.</span><span>.</span><span>.</span></span>');
        $('#msai-log').append(loadingDiv);
        scrollToLastUserMessage();

        var ajaxData = {
            action: 'bootshas_chat',
            message: activeFilterQuery,
            nonce: bootshas_ajax.nonce,
            session_id: msaiSessionId
        };
        $.extend(ajaxData, filterData);

        $.ajax({
            url: bootshas_ajax.ajax_url,
            type: 'POST',
            data: ajaxData,
            success: function(response) {
                loadingDiv.remove();
                if (response.success) {
                    if (response.data.text) {
                        addMessageToLog('bot', response.data.text);
                    }
                    if (response.data.filters && response.data.original_query) {
                        displayFilterChips(response.data.filters, response.data.original_query);
                    }
                    if (response.data.products) {
                        displayProducts(response.data.products, response.data.mode);
                    }
                } else {
                    addMessageToLog('bot', response.data.text || t('no_filter_results'));
                }
            },
            error: function() {
                loadingDiv.remove();
                addMessageToLog('bot', t('error_server'));
            }
        });
    }

    function displayGiftQuestions(questions) {
        const log = $('#msai-log');
        const questionsDiv = $('<div>').addClass('msai-message msai-bot msai-gift-questions');

        questions.forEach(function(question) {
            const chip = $('<button>')
                .addClass('msai-btn msai-gift-chip')
                .text(question)
                .css({
                    'display': 'block',
                    'width': '100%',
                    'text-align': 'left',
                    'margin': '4px 0',
                    'padding': '8px 12px',
                    'background': '#f0f4f8',
                    'border': '1px solid #d0d7de',
                    'border-radius': '8px',
                    'cursor': 'pointer',
                    'font-size': '13px',
                    'color': '#333',
                    'transition': 'background 0.2s'
                })
                .on('mouseenter', function() { $(this).css('background', '#e1e8ef'); })
                .on('mouseleave', function() { $(this).css('background', '#f0f4f8'); })
                .on('click', function() {
                    // Put question text into input for user to complete
                    $('#msai-q').val(t('gift_prefix') + question.toLowerCase().replace(/[?.]/g, '') + ' ').focus();
                });
            questionsDiv.append(chip);
        });

        log.append(questionsDiv);
        saveToHistory({type: 'questions', questions: questions});
        scrollToLastUserMessage();
    }

    // ---- Voice-to-Cart: detect add-to-cart intent and match against displayed products ----

    // Decode HTML entities (e.g. &euro; → €)
    function decodeHtmlEntities(str) {
        var el = document.createElement('textarea');
        el.innerHTML = str;
        return el.value;
    }

    // Cart trigger phrases in all supported languages
    var cartTriggers = [
        // LV
        'ieliec grozā', 'pievieno grozam', 'ievieto grozā', 'gribu nopirkt',
        'pievienot grozam', 'ielikt grozā', 'likt grozā', 'pievieno groza',
        'pievienot groza', 'ielikt groza', 'likt groza', 'grozā', 'groza',
        // EN
        'add to cart', 'put in cart', 'i want to buy', 'buy', 'purchase', 'add to basket',
        // DE
        'in den warenkorb', 'kaufen', 'ich möchte kaufen', 'warenkorb',
        // RU
        'в корзину', 'добавь в корзину', 'купить', 'хочу купить', 'добавить в корзину',
        // LT
        'į krepšelį', 'pridėti į krepšelį', 'noriu nupirkti', 'pirkti',
        // ET
        'lisa ostukorvi', 'osta', 'tahan osta',
        // ES
        'añadir al carrito', 'comprar', 'quiero comprar', 'al carrito',
        // FR
        'ajouter au panier', 'acheter', 'je veux acheter', 'au panier'
    ];

    /**
     * Check if message contains a cart trigger and extract product name.
     * Returns {trigger: string, productName: string} or null.
     */
    // Strip diacritics for fuzzy trigger matching (LV/DE/RU/FR/ES/LT/ET)
    function stripDiacritics(str) {
        return str.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    }

    function detectCartIntent(message) {
        var trimmed = message.trim();
        var lower = trimmed.toLowerCase();
        var norm = stripDiacritics(lower);
        // Try each trigger — longest first for best match
        var sorted = cartTriggers.slice().sort(function(a, b) { return b.length - a.length; });
        for (var i = 0; i < sorted.length; i++) {
            var trig = sorted[i];
            var normTrig = stripDiacritics(trig);
            var pos = norm.indexOf(normTrig);
            if (pos !== -1) {
                // Extract the part that is NOT the trigger (= product name)
                var before = trimmed.substring(0, pos).trim();
                var after = trimmed.substring(pos + normTrig.length).trim();
                var productName = (before + ' ' + after).trim();
                // Remove common punctuation/quotes from edges
                productName = productName.replace(/^[\s"'„"«»—\-–]+|[\s"'„"«»—\-–.,!?]+$/g, '');
                return { trigger: trig, productName: productName.length >= 2 ? productName : '' };
            }
        }
        return null;
    }

    /**
     * Match a spoken product name against lastDisplayedProducts.
     * Returns array of {product, score} sorted by score desc.
     */
    function matchDisplayedProducts(spokenName) {
        if (!lastDisplayedProducts.length) return [];
        var spoken = stripDiacritics(spokenName.toLowerCase());
        var spokenWords = spoken.split(/\s+/).filter(function(w) { return w.length >= 2; });
        var matches = [];

        lastDisplayedProducts.forEach(function(product) {
            var title = stripDiacritics((product.title || '').toLowerCase());
            var score = 0;

            // Exact full match
            if (title === spoken) {
                score = 10000;
            }
            // Title contains spoken text as substring
            else if (title.indexOf(spoken) !== -1) {
                score = 5000;
            }
            // Spoken text contains title as substring
            else if (spoken.indexOf(title) !== -1) {
                score = 4000;
            }
            else {
                // Per-word matching
                var titleWords = title.split(/[\s\-\/.,]+/).filter(function(w) { return w.length >= 2; });
                var matched = 0;
                spokenWords.forEach(function(sw) {
                    for (var j = 0; j < titleWords.length; j++) {
                        if (titleWords[j].indexOf(sw) !== -1 || sw.indexOf(titleWords[j]) !== -1) {
                            matched++;
                            break;
                        }
                    }
                });
                if (matched > 0) {
                    score = matched * 500 + (matched / Math.max(spokenWords.length, 1)) * 1000;
                }
            }

            if (score > 0) {
                matches.push({ product: product, score: score });
            }
        });

        matches.sort(function(a, b) { return b.score - a.score; });
        return matches;
    }

    /**
     * Show cart confirmation message with Jā/Nē buttons.
     */
    function showCartConfirmation(product) {
        var priceText = escHtml(product.price + ' ' + decodeHtmlEntities(product.currency || '€'));
        var msg = t('voice_cart_confirm', { product: escHtml(product.title), price: priceText });

        var log = $('#msai-log');
        var confirmDiv = $('<div>').addClass('msai-message msai-bot msai-cart-confirm');

        // Message text (simple bold via replacing ** with <strong>)
        var htmlMsg = msg.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        confirmDiv.append($('<p>').html(htmlMsg));

        // Button row
        var btnRow = $('<div>').addClass('msai-cart-confirm-buttons');

        var yesBtn = $('<button>').addClass('msai-btn msai-btn-cart-yes')
            .text(t('voice_cart_yes'))
            .on('click', function() {
                btnRow.remove();
                // Add to cart
                $.post(bootshas_ajax.ajax_url, {
                    action: 'bootshas_add_to_cart',
                    nonce: bootshas_ajax.nonce,
                    product_id: product.id,
                    quantity: 1,
                    session_id: msaiSessionId
                }).done(function(response) {
                    if (response.success) {
                        var addedMsg = t('voice_cart_added', { product: escHtml(product.title) });
                        var addedHtml = addedMsg.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
                        confirmDiv.html($('<p>').html(addedHtml));
                        $(document.body).trigger('wc_fragment_refresh');
                        // Also update the card button if visible
                        $('.msai-card[data-product-id="' + product.id + '"] .msai-btn-cart')
                            .text(t('btn_added'))
                            .css({'background-color': '#1a6b2a', 'cursor': 'default'})
                            .off('click')
                            .prop('disabled', false);
                        // Show shipping method selection
                        showShippingSelection();
                    } else {
                        confirmDiv.html($('<p>').text(t('btn_error')));
                    }
                }).fail(function() {
                    confirmDiv.html($('<p>').text(t('btn_error_retry')));
                });
            });

        var noBtn = $('<button>').addClass('msai-btn msai-btn-cart-no')
            .text(t('voice_cart_no'))
            .on('click', function() {
                btnRow.remove();
                confirmDiv.html($('<p>').text(t('voice_cart_cancelled')));
            });

        btnRow.append(yesBtn).append(noBtn);
        confirmDiv.append(btnRow);
        log.append(confirmDiv);
        scrollToElement(confirmDiv);
    }

    /**
     * Show list of multiple matched products for user to choose.
     */
    function showCartMultipleChoices(matches) {
        var log = $('#msai-log');
        var choiceDiv = $('<div>').addClass('msai-message msai-bot msai-cart-choices');
        choiceDiv.append($('<p>').text(t('voice_cart_multiple', { n: matches.length })));

        var listDiv = $('<div>').addClass('msai-cart-choice-list');
        matches.forEach(function(m) {
            var product = m.product;
            var priceText = product.price + ' ' + decodeHtmlEntities(product.currency || '€');
            var btn = $('<button>').addClass('msai-btn msai-btn-cart-choice')
                .on('click', function() {
                    choiceDiv.remove();
                    if (product.stock_status !== 'instock') {
                        var oosMsg = t('voice_cart_out_of_stock', { product: product.title })
                            .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
                        addBotHtml(oosMsg);
                    } else {
                        showCartConfirmation(product);
                    }
                });
            if (product.image) {
                var choiceImg = $('<img>').addClass('msai-choice-img').attr('alt', product.title);
                applyImageFallback(choiceImg, getImageCandidates(product), product.title);
                btn.append(choiceImg);
            }
            btn.append($('<span>').addClass('msai-choice-text').text(product.title + ' — ' + priceText));
            listDiv.append(btn);
        });
        choiceDiv.append(listDiv);
        log.append(choiceDiv);
        scrollToElement(choiceDiv);
    }

    /** Helper: append bot message with HTML (sanitized) */
    function addBotHtml(html) {
        var log = $('#msai-log');
        var div = $('<div>').addClass('msai-message msai-bot');
        div.append($('<p>').html(sanitizeBotHtml(html)));
        log.append(div);
        scrollToElement(div);
    }

    /**
     * Shipping selection: fetch available methods from WC and show buttons.
     */
    var shippingMethods = []; // cached for voice matching

    function showShippingSelection() {
        var log = $('#msai-log');
        var loadDiv = $('<div>').addClass('msai-message msai-bot msai-shipping-loading');
        loadDiv.append($('<p>').text(t('shipping_loading')));
        log.append(loadDiv);
        scrollToElement(loadDiv);

        $.post(bootshas_ajax.ajax_url, {
            action: 'bootshas_get_shipping',
            nonce: bootshas_ajax.nonce
        }).done(function(response) {
            loadDiv.remove();
            if (response.success && response.data.methods.length) {
                shippingMethods = response.data.methods;
                var currency = decodeHtmlEntities(response.data.currency || '€');
                renderShippingButtons(response.data.methods, currency);
            } else {
                addBotHtml(t('shipping_none'));
            }
        }).fail(function() {
            loadDiv.remove();
            addBotHtml(t('shipping_error'));
        });
    }

    function renderShippingButtons(methods, currency) {
        var log = $('#msai-log');
        var shipDiv = $('<div>').addClass('msai-message msai-bot msai-shipping-select');
        shipDiv.append($('<p>').text(t('shipping_choose')));

        var listDiv = $('<div>').addClass('msai-shipping-list');
        methods.forEach(function(method) {
            var costText = method.cost > 0 ? (method.cost.toFixed(2) + ' ' + currency) : t('shipping_free');
            var btn = $('<button>').addClass('msai-btn msai-btn-shipping')
                .on('click', function() {
                    selectShippingMethod(method, shipDiv);
                });
            btn.append($('<span>').addClass('msai-shipping-label').text(method.label));
            btn.append($('<span>').addClass('msai-shipping-cost').text(costText));
            listDiv.append(btn);
        });

        // Skip button — go to checkout without pre-selecting shipping
        var skipBtn = $('<button>').addClass('msai-btn msai-btn-shipping msai-btn-shipping-skip')
            .text(t('shipping_skip'))
            .on('click', function() {
                window.location.href = (bootshas_ajax.checkout_url || '/checkout/');
            });
        listDiv.append(skipBtn);

        shipDiv.append(listDiv);
        log.append(shipDiv);
        scrollToElement(shipDiv);
    }

    function selectShippingMethod(method, containerDiv) {
        containerDiv.find('.msai-shipping-list').remove();
        containerDiv.html($('<p>').text(t('shipping_selected', { method: method.label })));

        $.post(bootshas_ajax.ajax_url, {
            action: 'bootshas_set_shipping',
            nonce: bootshas_ajax.nonce,
            method_id: method.id
        }).done(function(response) {
            if (response.success) {
                var redirectMsg = t('shipping_redirect');
                addBotHtml('✅ ' + redirectMsg);
                setTimeout(function() {
                    window.location.href = response.data.checkout_url || '/checkout/';
                }, 1200);
            } else {
                addBotHtml(t('shipping_error'));
            }
        }).fail(function() {
            addBotHtml(t('shipping_error'));
        });
    }

    /**
     * Voice shipping detection: match user message against cached shipping methods.
     * Returns true if handled.
     */
    function handleShippingVoice(message) {
        if (!shippingMethods.length) return false;
        var msg = message.toLowerCase().trim();
        var best = null;
        var bestScore = 0;

        shippingMethods.forEach(function(method) {
            var label = method.label.toLowerCase();
            var score = 0;
            // Exact match
            if (msg === label) { score = 10000; }
            // Message contains full label
            else if (msg.indexOf(label) !== -1) { score = 5000; }
            // Label contains message
            else if (label.indexOf(msg) !== -1) { score = 4000; }
            else {
                // Word matching
                var mWords = msg.split(/\s+/).filter(function(w) { return w.length >= 2; });
                var lWords = label.split(/[\s\-\/.,]+/).filter(function(w) { return w.length >= 2; });
                var matched = 0;
                mWords.forEach(function(mw) {
                    for (var j = 0; j < lWords.length; j++) {
                        if (lWords[j].indexOf(mw) !== -1 || mw.indexOf(lWords[j]) !== -1) {
                            matched++;
                            break;
                        }
                    }
                });
                if (matched > 0) {
                    score = matched * 500 + (matched / Math.max(mWords.length, 1)) * 1000;
                }
            }
            if (score > bestScore) {
                bestScore = score;
                best = method;
            }
        });

        if (best && bestScore >= 500) {
            addMessageToLog('user', message);
            $('#msai-q').val('');
            // Remove the shipping selection UI if still visible
            $('.msai-shipping-select').last().find('.msai-shipping-list').remove();
            $('.msai-shipping-select').last().html($('<p>').text(t('shipping_selected', { method: best.label })));
            selectShippingMethod(best, $('<div>'));
            return true;
        }
        return false;
    }

    /**
     * Main entry: handle cart intent from user message.
     * Returns true if handled, false if should continue with normal search.
     */
    function handleCartIntent(message) {
        var intent = detectCartIntent(message);
        if (!intent) return false;

        addMessageToLog('user', message);
        $('#msai-q').val('');

        // No products displayed yet
        if (!lastDisplayedProducts.length) {
            addMessageToLog('bot', t('voice_cart_no_products'));
            return true;
        }

        // No product name specified — show all displayed products as choices
        if (!intent.productName) {
            var allChoices = lastDisplayedProducts.map(function(p) { return { product: p, score: 1 }; });
            showCartMultipleChoices(allChoices.slice(0, 5));
            return true;
        }

        var matches = matchDisplayedProducts(intent.productName);

        if (matches.length === 0) {
            addMessageToLog('bot', t('voice_cart_not_found'));
        } else if (matches.length === 1 || (matches[0].score >= 4000 && matches[0].score > matches[1]?.score * 1.5)) {
            // Clear winner
            var product = matches[0].product;
            if (product.stock_status !== 'instock') {
                var oosMsg = t('voice_cart_out_of_stock', { product: product.title })
                    .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
                addBotHtml(oosMsg);
            } else {
                showCartConfirmation(product);
            }
        } else {
            // Multiple matches — show top 5 max
            showCartMultipleChoices(matches.slice(0, 5));
        }

        return true;
    }

    // Audio level monitoring — visual feedback on recording button
    function startAudioLevelMonitor() {
        navigator.mediaDevices.getUserMedia({ audio: true }).then(function(stream) {
            audioStream = stream;
            audioContext = new (window.AudioContext || window.webkitAudioContext)();
            var source = audioContext.createMediaStreamSource(stream);
            audioAnalyser = audioContext.createAnalyser();
            audioAnalyser.fftSize = 256;
            audioAnalyser.smoothingTimeConstant = 0.5;
            source.connect(audioAnalyser);

            var dataArray = new Uint8Array(audioAnalyser.frequencyBinCount);
            var btn = document.getElementById('msai-smart-btn');

            function updateLevel() {
                if (!isRecording) return;
                audioAnalyser.getByteFrequencyData(dataArray);
                // Calculate average volume (0-255)
                var sum = 0;
                for (var i = 0; i < dataArray.length; i++) sum += dataArray[i];
                var avg = sum / dataArray.length;
                // Normalize to 0-1 range, with some amplification for speech
                var level = Math.min(1, avg / 80);

                if (btn) {
                    // Scale button glow and size based on audio level
                    var glow = Math.round(4 + level * 16);
                    var scale = 1 + level * 0.08;
                    btn.style.boxShadow = '0 0 ' + glow + 'px ' + Math.round(glow / 2) + 'px rgba(239,68,68,' + (0.3 + level * 0.5) + ')';
                    btn.style.transform = 'scale(' + scale + ')';
                }
                audioLevelRAF = requestAnimationFrame(updateLevel);
            }
            audioLevelRAF = requestAnimationFrame(updateLevel);
        }).catch(function() {
            // Mic not available — skip the audio-level animation.
        });
    }

    function stopAudioLevelMonitor() {
        if (audioLevelRAF) { cancelAnimationFrame(audioLevelRAF); audioLevelRAF = null; }
        if (audioAnalyser) { audioAnalyser = null; }
        if (audioContext) { try { audioContext.close(); } catch(e) {} audioContext = null; }
        if (audioStream) { audioStream.getTracks().forEach(function(t) { t.stop(); }); audioStream = null; }
        var btn = document.getElementById('msai-smart-btn');
        if (btn) { btn.style.boxShadow = ''; btn.style.transform = ''; }
    }

    function canUseCloudSpeechFallback() {
        var pro = (bootshas_ajax && bootshas_ajax.pro) ? bootshas_ajax.pro : null;
        if (!pro || !pro.enabled || !pro.voice) return false;
        var mode = pro.voice.mode || 'browser_fallback';
        if (mode === 'browser_only') return false;
        return !!pro.voice.google_connected;
    }

    function isGoogleOnlyMode() {
        var pro = (bootshas_ajax && bootshas_ajax.pro) ? bootshas_ajax.pro : null;
        if (!pro || !pro.voice) return false;
        return (pro.voice.mode || '') === 'google_only' && !!pro.voice.google_connected;
    }

    function cloudSpeechMaxSeconds() {
        var pro = (bootshas_ajax && bootshas_ajax.pro) ? bootshas_ajax.pro : null;
        var v = pro && pro.voice && pro.voice.max_seconds ? parseInt(pro.voice.max_seconds, 10) : 15;
        if (!v || v < 1) v = 15;
        return v;
    }

    function isSecureVoiceContext() {
        var host = (window.location && window.location.hostname) ? window.location.hostname : '';
        var isLocalhost = host === 'localhost' || host === '127.0.0.1' || host === '::1';
        return !!window.isSecureContext || isLocalhost;
    }

    function sendCloudSpeechBlob(blob, mimeType) {
        var fd = new FormData();
        fd.append('action', 'bootflow_shop_assist_pro_speech_to_text');
        fd.append('nonce', bootshas_ajax.nonce || '');
        fd.append('lang', t('speech_lang') || 'en-US');
        fd.append('audio', blob, 'voice.' + ((mimeType || '').indexOf('ogg') !== -1 ? 'ogg' : 'webm'));

        $.ajax({
            url: bootshas_ajax.ajax_url,
            type: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response && response.success && response.data && response.data.transcript) {
                    handleVoiceResult(response.data.transcript);
                    return;
                }
                addMessageToLog('bot', (response && response.data && response.data.text) ? response.data.text : t('voice_no_result'), true);
            },
            error: function(xhr) {
                var txt = t('voice_no_result');
                if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.text) {
                    txt = xhr.responseJSON.data.text;
                }
                addMessageToLog('bot', txt, true);
            },
            complete: function() {
                isRecording = false;
                usingCloudSpeech = false;
                $('#msai-smart-btn').removeClass('msai-recording').prop('disabled', false);
                updateSmartButton();
            }
        });
    }

    function startCloudRecording() {
        if (!isSecureVoiceContext()) {
            addMessageToLog('bot', 'Balss ievade šajā pārlūkā prasa drošu savienojumu (HTTPS). Atver veikalu ar https:// vai testē uz localhost.', true);
            return;
        }

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || typeof MediaRecorder === 'undefined') {
            addMessageToLog('bot', t('voice_not_supported'), true);
            return;
        }
        if (isRecording) return;

        navigator.mediaDevices.getUserMedia({ audio: true }).then(function(stream) {
            var options = {};
            if (MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported('audio/webm;codecs=opus')) {
                options.mimeType = 'audio/webm;codecs=opus';
            } else if (MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported('audio/ogg;codecs=opus')) {
                options.mimeType = 'audio/ogg;codecs=opus';
            }

            mediaRecorder = new MediaRecorder(stream, options);
            mediaChunks = [];
            usingCloudSpeech = true;

            mediaRecorder.onstart = function() {
                isRecording = true;
                $('#msai-smart-btn').text(t('voice_stop')).addClass('msai-recording');
                addMessageToLog('bot', t('voice_listening'), true);
                $('#msai-log').scrollTop($('#msai-log')[0].scrollHeight);

                mediaStopTimer = setTimeout(function() {
                    if (mediaRecorder && mediaRecorder.state !== 'inactive') {
                        mediaRecorder.stop();
                    }
                }, cloudSpeechMaxSeconds() * 1000);
            };

            mediaRecorder.ondataavailable = function(e) {
                if (e.data && e.data.size > 0) mediaChunks.push(e.data);
            };

            mediaRecorder.onstop = function() {
                clearTimeout(mediaStopTimer);
                mediaStopTimer = null;
                stopAudioLevelMonitor();
                stream.getTracks().forEach(function(t) { t.stop(); });

                if (!mediaChunks.length) {
                    isRecording = false;
                    usingCloudSpeech = false;
                    $('#msai-smart-btn').removeClass('msai-recording').prop('disabled', false);
                    updateSmartButton();
                    addMessageToLog('bot', t('voice_no_result'), true);
                    return;
                }

                var mimeType = mediaChunks[0].type || (options.mimeType || 'audio/webm');
                var blob = new Blob(mediaChunks, { type: mimeType });
                sendCloudSpeechBlob(blob, mimeType);
            };

            mediaRecorder.onerror = function() {
                clearTimeout(mediaStopTimer);
                mediaStopTimer = null;
                stopAudioLevelMonitor();
                stream.getTracks().forEach(function(t) { t.stop(); });
                isRecording = false;
                usingCloudSpeech = false;
                $('#msai-smart-btn').removeClass('msai-recording').prop('disabled', false);
                updateSmartButton();
                addMessageToLog('bot', t('voice_no_result'), true);
            };

            mediaRecorder.start();
        }).catch(function() {
            addMessageToLog('bot', t('voice_no_mic'), true);
        });
    }

    // Start browser-based voice recognition when supported.
    function startRecording() {
        var forceGoogle = isGoogleOnlyMode();
        if (forceGoogle) {
            startCloudRecording();
            return;
        }

        if (!useWebSpeech) {
            // Firefox and other non-Chrome browsers: show browser compatibility message
            var isFirefox = /Firefox/i.test(navigator.userAgent || '');
            if (isFirefox && window.console && window.console.log) {
                console.log('[Bootflow Shop Assist] Firefox detected - voice input not available (use Chrome/Edge)');
            }
            if (canUseCloudSpeechFallback()) {
                startCloudRecording();
                return;
            }
            addMessageToLog('bot', t('voice_not_supported'), true);
            return;
        }

        var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!SpeechRecognition) {
            useWebSpeech = false;
            if (canUseCloudSpeechFallback()) {
                startCloudRecording();
                return;
            }
            addMessageToLog('bot', t('voice_not_supported'), true);
            return;
        }
        if (isRecording) return;

        speechRecognition = new SpeechRecognition();
        speechRecognition.lang = t('speech_lang');
        speechRecognition.interimResults = true;
        speechRecognition.maxAlternatives = 1;
        // Mobile: single session (no continuous) — avoids restart loop
        speechRecognition.continuous = !isMobile;

        var finalTranscript = '';
        var accumulatedTranscript = '';
        listeningMsgShown = false;
        voiceStopIntentional = false;

        var srOnStart = function() {
            isRecording = true;
            // No getUserMedia audio monitor for Web Speech — dual mic streams kill Chrome.
            // CSS .msai-recording class provides visual pulse animation.
            finalTranscript = '';
            if (!listeningMsgShown) {
                listeningMsgShown = true;
                $('#msai-smart-btn').text(t('voice_stop')).addClass('msai-recording');
                addMessageToLog('bot', t('voice_listening'), true);
                $('#msai-log').scrollTop($('#msai-log')[0].scrollHeight);
            }
        };

        var srOnResult = function(event) {
            // Reset silence timer on every new result
            clearTimeout(speechSilenceTimer);

            var interim = '';
            finalTranscript = '';
            for (var i = 0; i < event.results.length; i++) {
                if (event.results[i].isFinal) {
                    finalTranscript += event.results[i][0].transcript;
                } else {
                    interim += event.results[i][0].transcript;
                }
            }

            // Show live preview in input (accumulated from previous sessions + current)
            var fullText = accumulatedTranscript + finalTranscript + interim;
            $('#msai-q').val(fullText);

            // Auto-stop after configured silence timeout (no new results)
            var silenceMs = (parseInt(bootshas_ajax.voice_silence, 10) || 4) * 1000;
            speechSilenceTimer = setTimeout(function() {
                voiceStopIntentional = true;
                if (speechRecognition && isRecording) {
                    speechRecognition.stop();
                }
            }, silenceMs);
        };

        var srOnError = function(event) {
            if (event.error === 'network' || event.error === 'service-not-allowed') {
                useWebSpeech = false;
                voiceStopIntentional = true;
                isRecording = false;
                $('#msai-smart-btn').text(t('btn_voice')).removeClass('msai-recording');
                if (canUseCloudSpeechFallback()) {
                    startCloudRecording();
                } else {
                    addMessageToLog('bot', t('voice_not_supported'), true);
                }
                return;
            }
            // Fatal errors — show message, stop
            if (event.error === 'not-allowed' || event.error === 'permission-denied') {
                useWebSpeech = false; // mic blocked — remember
                addMessageToLog('bot', t('voice_no_mic'), true);
                isRecording = false;
                $('#msai-smart-btn').text(t('btn_voice')).removeClass('msai-recording');
                return;
            }
            // Non-fatal errors — onend will auto-restart, so don't show anything
            // (no-speech, aborted, audio-capture, etc.)
        };

        var srRestartCount = 0;
        var srLastStartTime = Date.now();
        var srMaxRestarts = 5;

        var srOnEnd = function() {
            clearTimeout(speechSilenceTimer);

            // Mobile: no restart — single session ends naturally, process result
            if (isMobile) {
                isRecording = false;
                $('#msai-smart-btn').text(t('btn_voice')).removeClass('msai-recording').prop('disabled', false);
                var text = (accumulatedTranscript + finalTranscript).trim() || ($('#msai-q').val() || '').trim();
                if (text) handleVoiceResult(text);
                return;
            }

            // Desktop Chrome: auto-restart with delay and preserve transcript.
            if (!voiceStopIntentional && isRecording) {
                accumulatedTranscript += finalTranscript;
                finalTranscript = '';

                // Guard against rapid restart loop (mobile Chrome / broken sessions)
                srRestartCount++;
                var timeSinceStart = Date.now() - srLastStartTime;

                // If session lasted < 500ms and we've restarted too many times → stop
                if (timeSinceStart < 500 && srRestartCount > srMaxRestarts) {
                    isRecording = false;
                    useWebSpeech = false;
                    $('#msai-smart-btn').text(t('btn_voice')).removeClass('msai-recording').prop('disabled', false);
                    var text = accumulatedTranscript.trim() || ($('#msai-q').val() || '').trim();
                    if (text) handleVoiceResult(text);
                    return;
                }

                // Normal restart — Chrome needs a delay
                setTimeout(function() {
                    if (!isRecording || voiceStopIntentional) return;
                    srLastStartTime = Date.now();
                    try {
                        speechRecognition.start();
                    } catch(e) {
                        // Old instance can't restart — create fresh one
                        try {
                            var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
                            speechRecognition = new SR();
                            speechRecognition.lang = t('speech_lang');
                            speechRecognition.interimResults = true;
                            speechRecognition.maxAlternatives = 1;
                            speechRecognition.continuous = true;
                            speechRecognition.onstart = srOnStart;
                            speechRecognition.onresult = srOnResult;
                            speechRecognition.onerror = srOnError;
                            speechRecognition.onend = srOnEnd;
                            speechRecognition.start();
                        } catch(e2) {
                            // Cannot restart at all — process what we have
                            isRecording = false;
                            $('#msai-smart-btn').text(t('btn_voice')).removeClass('msai-recording').prop('disabled', false);
                            var text = accumulatedTranscript.trim() || ($('#msai-q').val() || '').trim();
                            if (text) handleVoiceResult(text);
                        }
                    }
                }, 300);
                return;
            }

            isRecording = false;
            $('#msai-smart-btn').text(t('btn_voice')).removeClass('msai-recording').prop('disabled', false);

            var text = (accumulatedTranscript + finalTranscript).trim() || ($('#msai-q').val() || '').trim();
            if (text) {
                handleVoiceResult(text);
            }
        };

        speechRecognition.onstart = srOnStart;
        speechRecognition.onresult = srOnResult;
        speechRecognition.onerror = srOnError;
        speechRecognition.onend = srOnEnd;

        speechRecognition.start();
    }

    // Handle successful voice transcription (shared by both methods)
    function handleVoiceResult(transcript) {
        // Speech engines often append trailing punctuation like '.'; keep query clean.
        var cleanedTranscript = String(transcript || '')
            .replace(/[\s\u00A0]+$/g, '')
            .replace(/[.!?]+$/g, '')
            .trim();
        if (!cleanedTranscript) {
            cleanedTranscript = String(transcript || '').trim();
        }

        $('#msai-q').val(cleanedTranscript);
        updateSmartButton();
        addMessageToLog('bot', t('voice_recognized') + cleanedTranscript + '"', true);
        
        // Scroll to show the recognized text
        setTimeout(function() {
            scrollToLastUserMessage();
        }, 100);

        var voiceMode = bootshas_ajax.voice_mode || 'delayed';
        if (voiceMode === 'instant') {
            setTimeout(function() { $('#msai-form').submit(); }, 100);
        } else if (voiceMode === 'delayed') {
            startVoiceCountdown();
        }
    }

    // Voice countdown for delayed mode
    function startVoiceCountdown() {
        cancelVoiceCountdown();
        var remaining = 3;
        var btn = $('#msai-smart-btn');
        var originalText = btn.text();
        btn.text(t('voice_auto_searching', {n: remaining})).addClass('msai-voice-countdown');

        voiceCountdownTimer = setInterval(function() {
            remaining--;
            if (remaining <= 0) {
                cancelVoiceCountdown();
                btn.removeClass('msai-voice-countdown');
                updateSmartButton();
                $('#msai-form').submit();
            } else {
                btn.text(t('voice_auto_searching', {n: remaining}));
            }
        }, 1000);

        // Cancel if user taps input field or edits text
        $('#msai-q').one('focus input', function() {
            cancelVoiceCountdown();
            btn.removeClass('msai-voice-countdown');
            updateSmartButton();
        });
    }

    function cancelVoiceCountdown() {
        if (voiceCountdownTimer) {
            clearInterval(voiceCountdownTimer);
            voiceCountdownTimer = null;
        }
    }

    // Stop voice recording
    function stopRecording() {
        clearTimeout(speechSilenceTimer);
        clearTimeout(mediaStopTimer);
        mediaStopTimer = null;
        voiceStopIntentional = true;
        if (speechRecognition && isRecording) {
            speechRecognition.stop();
        }
        if (mediaRecorder && mediaRecorder.state && mediaRecorder.state !== 'inactive') {
            try { mediaRecorder.stop(); } catch (e) { /* ignore */ }
        }
        isRecording = false;
        usingCloudSpeech = false;
        stopAudioLevelMonitor();
        $('#msai-smart-btn').removeClass('msai-recording').prop('disabled', false);
        updateSmartButton();
    }

    // Debug function - can be called from browser console
    window.debugSpeechRecognition = function() {
        var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        return {
            protocol: location.protocol,
            hostname: location.hostname,
            speechAvailable: !!SpeechRecognition,
            language: t('speech_lang')
        };
    };

    // Initialize when document is ready
    $(document).ready(function() {
        // Check if required variables are defined
        if (typeof bootshas_ajax === 'undefined') {
            return;
        }

        // Ensure floating button exists
        if ($('#bootflow-shop-assist-floating-btn').length === 0) {
            var wlIcon = bootshas_ajax.wl_icon || '💬';
            $('body').append('<div id="bootflow-shop-assist-floating-btn">' + wlIcon + '</div>');
        }

        // Dedupe: remove extra floating buttons injected by other scripts
        var duplicateBtns = $('[id="bootflow-shop-assist-floating-btn"]');
        if (duplicateBtns.length > 1) {
            duplicateBtns.not(':first').remove();
        }

        initChatbot();
        restoreChatHistory();
        // Show starter questions if chat is empty (no history restored)
        if ($('#msai-log').children().length === 0) {
            showStarterQuestions();
        }
        // Auto-open chat if returning from product click
        var reopenMode = sessionStorage.getItem('msai_reopen');
        if (reopenMode) {
            sessionStorage.removeItem('msai_reopen');
            var clickedProduct = sessionStorage.getItem('msai_clicked_product');
            sessionStorage.removeItem('msai_clicked_product');
            if (clickedProduct) pendingScrollToProduct = clickedProduct;
            if (reopenMode === '1') {
                openModal();
            } else if (reopenMode === 'link') {
                // Desktop: show minimized (visible but compact)
                if ($(window).width() >= 768) {
                    var modal = $('#bootflow-shop-assist-chatbot');
                    modal.removeClass('modal-open').show();
                    updateToggleTitle();
                    // Scroll to the clicked product card
                    if (pendingScrollToProduct) {
                        setTimeout(function() {
                            scrollToProductCard(pendingScrollToProduct);
                            pendingScrollPos = null;
                        }, 100);
                    } else if (pendingScrollPos !== null) {
                        setTimeout(function() {
                            $('#msai-log').scrollTop(pendingScrollPos);
                            pendingScrollPos = null;
                        }, 50);
                    }
                    $('[id="bootflow-shop-assist-floating-btn"]').each(function() {
                        try {
                            $(this).addClass('hidden');
                            this.style.setProperty('display', 'none', 'important');
                        } catch(e) {}
                    });
                }
                // Mobile: stays closed, floating button visible
            }
        }
        // Save scroll position when navigating away
        $(window).on('beforeunload', function() {
            if ($('#bootflow-shop-assist-chatbot').is(':visible')) {
                saveScrollOnly();
            }
        });

    });

    } catch (error) {
        console.error('[SHOP ASSISTANT] Fatal error:', error);
        // Try to show button even if script fails
        setTimeout(function() {
            if (typeof jQuery !== 'undefined' && jQuery('#bootflow-shop-assist-floating-btn').length === 0) {
                jQuery('body').append('<div id="bootflow-shop-assist-floating-btn">💬</div>');
            }
        }, 1000);
    }

})(jQuery);
