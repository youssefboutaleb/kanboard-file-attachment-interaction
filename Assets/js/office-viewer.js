/**
 * High-Fidelity Office Document & Presentation Preview Engine (DOCX & PPTX)
 * FileInteractionCore for Kanboard.
 */
(function (window, document) {
    'use strict';

    function initOfficeViewers() {
        initDocxViewers();
        initPptxViewers();
    }

    /**
     * Render Word (.docx) documents with full formatting, typography,
     * margins, tables, drawings, and embedded images using docx-preview.
     */
    function initDocxViewers() {
        var docxContainers = document.querySelectorAll('.fic-docx-container:not([data-fic-initialized])');
        for (var i = 0; i < docxContainers.length; i++) {
            (function (container) {
                container.setAttribute('data-fic-initialized', 'true');
                var streamUrl = container.getAttribute('data-fic-stream-url');
                var target = container.querySelector('.fic-docx-render-target');
                var loading = container.querySelector('.fic-office-loading');
                var errorBox = container.querySelector('.fic-office-error');

                if (!streamUrl || !target) {
                    return;
                }

                fetch(streamUrl, { credentials: 'same-origin' })
                    .then(function (response) {
                        if (!response.ok) {
                            throw new Error('HTTP error ' + response.status);
                        }
                        return response.arrayBuffer();
                    })
                    .then(function (arrayBuffer) {
                        if (window.docx && typeof window.docx.renderAsync === 'function') {
                            return window.docx.renderAsync(arrayBuffer, target, undefined, {
                                inWrapper: true,
                                ignoreWidth: false,
                                ignoreHeight: false,
                                renderHeaders: true,
                                renderFooters: true,
                                useBase64URL: true,
                                breakPages: true
                            });
                        } else {
                            throw new Error('docx-preview library not loaded');
                        }
                    })
                    .then(function () {
                        if (loading) {
                            loading.style.display = 'none';
                        }
                        target.style.display = 'block';
                    })
                    .catch(function (err) {
                        console.warn('DOCX client rendering fallback:', err);
                        if (loading) {
                            loading.style.display = 'none';
                        }
                        if (errorBox) {
                            errorBox.style.display = 'block';
                        }
                    });
            })(docxContainers[i]);
        }
    }

    /**
     * Render PowerPoint (.pptx) presentations with full slide layouts,
     * shapes, theme colors, typography, tables, and slide deck navigation.
     */
    function initPptxViewers() {
        var pptxContainers = document.querySelectorAll('.fic-pptx-container:not([data-fic-initialized])');
        for (var i = 0; i < pptxContainers.length; i++) {
            (function (container) {
                container.setAttribute('data-fic-initialized', 'true');
                var streamUrl = container.getAttribute('data-fic-stream-url');
                var renderTarget = container.querySelector('.fic-pptx-render-target');
                var loading = container.querySelector('.fic-office-loading');
                var fallbackViewport = container.querySelector('.fic-pptx-deck-viewport');
                var counter = container.querySelector('.fic-pptx-counter');
                var prevBtn = container.querySelector('.fic-pptx-prev');
                var nextBtn = container.querySelector('.fic-pptx-next');
                var tabs = container.querySelectorAll('.fic-slide-tab');
                var panels = container.querySelectorAll('.fic-slide-panel');

                var presentation = null;
                var currentSlideIndex = 0;
                var totalSlides = parseInt(container.getAttribute('data-slide-count') || '1', 10);
                if (panels.length > 0) {
                    totalSlides = panels.length;
                }

                function showSlide(index) {
                    if (totalSlides === 0) {
                        return;
                    }
                    if (index < 0) {
                        index = 0;
                    }
                    if (index >= totalSlides) {
                        index = totalSlides - 1;
                    }
                    currentSlideIndex = index;
                    container.setAttribute('data-current-slide', currentSlideIndex.toString());

                    if (counter) {
                        counter.textContent = 'Slide ' + (currentSlideIndex + 1) + ' of ' + totalSlides;
                    }

                    if (prevBtn) {
                        prevBtn.disabled = currentSlideIndex === 0;
                        prevBtn.style.opacity = currentSlideIndex === 0 ? '0.5' : '1';
                        prevBtn.style.cursor = currentSlideIndex === 0 ? 'not-allowed' : 'pointer';
                    }
                    if (nextBtn) {
                        nextBtn.disabled = currentSlideIndex === totalSlides - 1;
                        nextBtn.style.opacity = currentSlideIndex === totalSlides - 1 ? '0.5' : '1';
                        nextBtn.style.cursor = currentSlideIndex === totalSlides - 1 ? 'not-allowed' : 'pointer';
                    }

                    // Update active tab styles
                    for (var t = 0; t < tabs.length; t++) {
                        var isCurrent = parseInt(tabs[t].getAttribute('data-slide-index'), 10) === currentSlideIndex;
                        tabs[t].classList.toggle('is-active', isCurrent);
                        tabs[t].setAttribute('aria-selected', isCurrent ? 'true' : 'false');
                        tabs[t].style.background = isCurrent ? '#d24726' : '#2f3542';
                        tabs[t].style.color = '#fff';
                        tabs[t].style.fontWeight = isCurrent ? '600' : 'normal';
                    }

                    // Render high-fidelity vector SVG slide if presentation loaded
                    if (presentation && renderTarget) {
                        renderTarget.innerHTML = '';
                        var slideWrapper = document.createElement('div');
                        slideWrapper.className = 'fic-pptx-slide-canvas-wrapper';
                        slideWrapper.style.boxShadow = '0 4px 16px rgba(0,0,0,0.35)';
                        slideWrapper.style.borderRadius = '4px';
                        slideWrapper.style.overflow = 'hidden';
                        slideWrapper.style.display = 'inline-block';
                        slideWrapper.style.background = '#fff';
                        renderTarget.appendChild(slideWrapper);

                        var viewerLib = window.PPTXViewer || window.pptxViewer;
                        if (viewerLib && typeof viewerLib.renderSlideToElement === 'function') {
                            try {
                                var targetWidth = Math.min(920, Math.max(380, container.clientWidth - 40));
                                viewerLib.renderSlideToElement(presentation, currentSlideIndex, slideWrapper, {
                                    width: targetWidth
                                });
                            } catch (renderErr) {
                                console.warn('Error rendering vector slide:', renderErr);
                            }
                        }
                    } else {
                        // Fallback: toggle DOM panels
                        for (var p = 0; p < panels.length; p++) {
                            var panelIndex = parseInt(panels[p].getAttribute('data-slide-index'), 10);
                            panels[p].style.display = panelIndex === currentSlideIndex ? '' : 'none';
                        }
                    }
                }

                // Expose controller methods on container for delegated listeners
                container._ficShowSlide = showSlide;
                container._ficGetCurrentSlide = function () { return currentSlideIndex; };
                container._ficGetTotalSlides = function () { return totalSlides; };

                showSlide(0);

                if (!streamUrl) {
                    return;
                }

                fetch(streamUrl, { credentials: 'same-origin' })
                    .then(function (response) {
                        if (!response.ok) {
                            throw new Error('HTTP error ' + response.status);
                        }
                        return response.arrayBuffer();
                    })
                    .then(function (arrayBuffer) {
                        var viewerLib = window.PPTXViewer || window.pptxViewer;
                        if (viewerLib && typeof viewerLib.loadPresentation === 'function') {
                            return viewerLib.loadPresentation(arrayBuffer);
                        } else {
                            throw new Error('PPTXViewer library not loaded');
                        }
                    })
                    .then(function (pres) {
                        presentation = pres;
                        if (pres && pres.slides && pres.slides.length > 0) {
                            totalSlides = pres.slides.length;
                        }

                        if (loading) {
                            loading.style.display = 'none';
                        }
                        if (fallbackViewport) {
                            fallbackViewport.style.display = 'none';
                        }
                        if (renderTarget) {
                            renderTarget.style.display = 'flex';
                        }

                        showSlide(0);
                    })
                    .catch(function (err) {
                        console.warn('PPTX rich client rendering fallback:', err);
                        if (loading) {
                            loading.style.display = 'none';
                        }
                        if (fallbackViewport) {
                            fallbackViewport.style.display = 'block';
                        }
                    });
            })(pptxContainers[i]);
        }
    }

    // Delegated click handler for PPTX navigation buttons and tabs
    document.addEventListener('click', function (event) {
        var prevBtn = event.target.closest ? event.target.closest('.fic-pptx-prev') : null;
        if (prevBtn) {
            event.preventDefault();
            var container = prevBtn.closest('.fic-pptx-container');
            if (container && typeof container._ficShowSlide === 'function') {
                container._ficShowSlide(container._ficGetCurrentSlide() - 1);
            }
            return;
        }

        var nextBtn = event.target.closest ? event.target.closest('.fic-pptx-next') : null;
        if (nextBtn) {
            event.preventDefault();
            var container = nextBtn.closest('.fic-pptx-container');
            if (container && typeof container._ficShowSlide === 'function') {
                container._ficShowSlide(container._ficGetCurrentSlide() + 1);
            }
            return;
        }

        var slideTab = event.target.closest ? event.target.closest('.fic-slide-tab') : null;
        if (slideTab) {
            event.preventDefault();
            var container = slideTab.closest('.fic-pptx-container');
            var idx = parseInt(slideTab.getAttribute('data-slide-index'), 10);
            if (container && !isNaN(idx) && typeof container._ficShowSlide === 'function') {
                container._ficShowSlide(idx);
            }
            return;
        }
    }, false);

    // Delegated keyboard navigation for presentation containers
    document.addEventListener('keydown', function (event) {
        var container = document.querySelector('.fic-pptx-container');
        if (!container || typeof container._ficShowSlide !== 'function') {
            return;
        }

        // Only handle navigation keys if focus is not in an input or textarea
        var tag = event.target ? event.target.tagName : '';
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') {
            return;
        }

        if (event.key === 'ArrowLeft' || event.key === 'PageUp') {
            event.preventDefault();
            container._ficShowSlide(container._ficGetCurrentSlide() - 1);
        } else if (event.key === 'ArrowRight' || event.key === 'PageDown' || event.key === ' ') {
            event.preventDefault();
            container._ficShowSlide(container._ficGetCurrentSlide() + 1);
        }
    }, false);

    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initOfficeViewers);
    } else {
        initOfficeViewers();
    }

    // Re-scan when Kanboard updates or opens modals
    if (window.MutationObserver) {
        var observer = new MutationObserver(function () {
            initOfficeViewers();
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }

    window.FIC = window.FIC || {};
    window.FIC.initOfficeViewers = initOfficeViewers;
})(window, document);
