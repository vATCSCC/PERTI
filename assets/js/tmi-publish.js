/**
 * TMI Publisher Orchestrator
 * 
 * Coordinates NTML Quick Entry and Advisory Builder with multi-Discord posting.
 * Manages staging/production workflow, cross-border detection, and publish results.
 * 
 * @package PERTI
 * @subpackage TMI
 * @version 1.0.0
 * @date 2026-01-27
 */

const TMIPublisher = (function() {
    'use strict';
    
    // Configuration from PHP
    const config = window.TMIPublisherConfig || {};
    
    // State
    let currentMode = 'staging'; // 'staging' or 'production'
    let selectedOrgs = [];
    let entryQueue = [];
    let isSubmitting = false;
    
    // DOM Elements
    let elements = {};
    
    /**
     * Initialize the publisher
     */
    function init() {
        console.log('[TMIPublisher] Initializing...');
        
        // Cache DOM elements
        cacheElements();
        
        // Set up event listeners
        bindEvents();
        
        // Initialize selected orgs from checkboxes
        initSelectedOrgs();
        
        // Start UTC clock
        startClock();
        
        // Initialize NTML module if present
        if (typeof NTMLParser !== 'undefined') {
            console.log('[TMIPublisher] NTML Parser available');
        }
        
        // Initialize Advisory Builder if present
        if (typeof AdvisoryBuilder !== 'undefined') {
            console.log('[TMIPublisher] Advisory Builder available');
        }
        
        console.log('[TMIPublisher] Initialized with config:', config);
    }
    
    /**
     * Cache DOM elements
     */
    function cacheElements() {
        elements = {
            // Mode controls
            publishModeBtns: document.querySelectorAll('.publish-mode-btn'),
            productionBanner: document.getElementById('productionBanner'),
            
            // Discord org selection
            discordOrgList: document.getElementById('discordOrgList'),
            orgCheckboxes: document.querySelectorAll('.discord-org-check input[type="checkbox"]'),
            crossBorderBadge: document.getElementById('crossBorderBadge'),
            
            // NTML Tab
            quickInput: document.getElementById('quickInput'),
            validFrom: document.getElementById('validFrom'),
            validUntil: document.getElementById('validUntil'),
            batchInput: document.getElementById('batchInput'),
            entryQueue: document.getElementById('entryQueue'),
            queueCount: document.getElementById('queueCount'),
            emptyQueueMsg: document.getElementById('emptyQueueMsg'),
            clearQueueBtn: document.getElementById('clearQueue'),
            ntmlSubmitArea: document.getElementById('ntmlSubmitArea'),
            submitCount: document.getElementById('submitCount'),
            submitAllBtn: document.getElementById('submitAllBtn'),
            previewBtn: document.getElementById('previewBtn'),
            
            // Mode toggle (single/batch)
            modeBtns: document.querySelectorAll('.mode-toggle .mode-btn'),
            singleMode: document.getElementById('singleMode'),
            batchMode: document.getElementById('batchMode'),
            
            // Template buttons
            templateBtns: document.querySelectorAll('.template-btn'),
            
            // Advisory Tab
            advisoryTypeCards: document.querySelectorAll('.advisory-type-card'),
            advisoryPreview: document.getElementById('adv_preview'),
            publishAdvisoryBtn: document.getElementById('btn_publish_advisory'),
            copyBtn: document.getElementById('btn_copy'),
            
            // Results
            publishResults: document.getElementById('publishResults'),
            publishResultsList: document.getElementById('publishResultsList'),
            
            // Modals
            previewModal: document.getElementById('previewModal'),
            previewContent: document.getElementById('previewContent'),
            submitFromPreview: document.getElementById('submitFromPreview'),
            
            // Clock
            utcClock: document.getElementById('utc_clock')
        };
    }
    
    /**
     * Bind event listeners
     */
    function bindEvents() {
        // Publish mode toggle (staging/production)
        elements.publishModeBtns.forEach(btn => {
            btn.addEventListener('click', () => setMode(btn.dataset.mode));
        });
        
        // Discord org checkboxes
        document.querySelectorAll('.discord-org-check').forEach(label => {
            label.addEventListener('click', (e) => {
                if (label.classList.contains('disabled')) {
                    e.preventDefault();
                    return;
                }
                
                const checkbox = label.querySelector('input[type="checkbox"]');
                if (e.target !== checkbox) {
                    checkbox.checked = !checkbox.checked;
                }
                
                label.classList.toggle('checked', checkbox.checked);
                updateSelectedOrgs();
            });
        });
        
        // NTML Quick Input
        if (elements.quickInput) {
            elements.quickInput.addEventListener('keydown', handleQuickInputKeydown);
            elements.quickInput.addEventListener('input', handleQuickInputChange);
        }
        
        // Batch input
        if (elements.batchInput) {
            elements.batchInput.addEventListener('input', handleBatchInputChange);
        }
        
        // Mode toggle (single/batch)
        elements.modeBtns.forEach(btn => {
            btn.addEventListener('click', () => setEntryMode(btn.dataset.mode));
        });
        
        // Template buttons
        elements.templateBtns.forEach(btn => {
            btn.addEventListener('click', () => applyTemplate(btn.dataset.template));
        });
        
        // Clear queue
        if (elements.clearQueueBtn) {
            elements.clearQueueBtn.addEventListener('click', clearQueue);
        }
        
        // Preview button
        if (elements.previewBtn) {
            elements.previewBtn.addEventListener('click', showPreview);
        }
        
        // Submit all
        if (elements.submitAllBtn) {
            elements.submitAllBtn.addEventListener('click', submitAllNTML);
        }
        
        // Submit from preview
        if (elements.submitFromPreview) {
            elements.submitFromPreview.addEventListener('click', () => {
                $('#previewModal').modal('hide');
                submitAllNTML();
            });
        }
        
        // Advisory type cards
        elements.advisoryTypeCards.forEach(card => {
            card.addEventListener('click', () => selectAdvisoryType(card.dataset.type));
        });
        
        // Publish advisory
        if (elements.publishAdvisoryBtn) {
            elements.publishAdvisoryBtn.addEventListener('click', publishAdvisory);
        }
        
        // Copy button
        if (elements.copyBtn) {
            elements.copyBtn.addEventListener('click', copyAdvisoryToClipboard);
        }
        
        // Tab change - detect cross-border on advisory changes
        $('a[data-toggle="tab"]').on('shown.bs.tab', function(e) {
            const tabId = e.target.getAttribute('href');
            console.log('[TMIPublisher] Tab changed to:', tabId);
        });
    }
    
    /**
     * Initialize selected orgs from DOM
     */
    function initSelectedOrgs() {
        selectedOrgs = [];
        document.querySelectorAll('.discord-org-check input:checked').forEach(checkbox => {
            selectedOrgs.push(checkbox.value);
        });
        console.log('[TMIPublisher] Initial selected orgs:', selectedOrgs);
    }
    
    /**
     * Update selected orgs list
     */
    function updateSelectedOrgs() {
        selectedOrgs = [];
        document.querySelectorAll('.discord-org-check input:checked').forEach(checkbox => {
            selectedOrgs.push(checkbox.value);
        });
        console.log('[TMIPublisher] Selected orgs:', selectedOrgs);
    }
    
    /**
     * Set publish mode (staging/production)
     */
    function setMode(mode) {
        currentMode = mode;
        
        elements.publishModeBtns.forEach(btn => {
            btn.classList.toggle('active', btn.dataset.mode === mode);
        });
        
        if (elements.productionBanner) {
            elements.productionBanner.classList.toggle('show', mode === 'production');
        }
        
        console.log('[TMIPublisher] Mode set to:', mode);
    }
    
    /**
     * Set entry mode (single/batch)
     */
    function setEntryMode(mode) {
        elements.modeBtns.forEach(btn => {
            btn.classList.toggle('active', btn.dataset.mode === mode);
        });
        
        if (elements.singleMode) {
            elements.singleMode.style.display = mode === 'single' ? 'block' : 'none';
        }
        if (elements.batchMode) {
            elements.batchMode.style.display = mode === 'batch' ? 'block' : 'none';
        }
    }
    
    /**
     * Handle quick input keydown
     */
    function handleQuickInputKeydown(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            
            if (e.ctrlKey || e.metaKey) {
                // Ctrl+Enter: Submit all
                if (entryQueue.length > 0) {
                    submitAllNTML();
                }
            } else {
                // Enter: Add to queue
                addEntryToQueue();
            }
        }
    }
    
    /**
     * Handle quick input change
     */
    function handleQuickInputChange() {
        const input = elements.quickInput.value.trim();
        
        // Check for cross-border facilities
        checkCrossBorder(input);
    }
    
    /**
     * Handle batch input change
     */
    function handleBatchInputChange() {
        const lines = elements.batchInput.value.split('\n').filter(l => l.trim());
        
        // Clear and rebuild queue from batch
        entryQueue = [];
        lines.forEach(line => {
            const parsed = parseNTMLEntry(line);
            if (parsed) {
                entryQueue.push(parsed);
            }
        });
        
        updateQueueDisplay();
    }
    
    /**
     * Add entry to queue
     */
    function addEntryToQueue() {
        const input = elements.quickInput.value.trim();
        if (!input) return;
        
        const validFrom = elements.validFrom.value.trim();
        const validUntil = elements.validUntil.value.trim();
        
        // Parse the entry
        const parsed = parseNTMLEntry(input, validFrom, validUntil);
        
        if (parsed) {
            entryQueue.push(parsed);
            elements.quickInput.value = '';
            updateQueueDisplay();
            
            // Check for cross-border
            checkCrossBorder(JSON.stringify(parsed));
        } else {
            // Show error feedback
            elements.quickInput.classList.add('is-invalid');
            setTimeout(() => elements.quickInput.classList.remove('is-invalid'), 2000);
        }
    }
    
    /**
     * Parse NTML entry (wrapper for NTMLParser if available)
     */
    function parseNTMLEntry(input, validFrom, validUntil) {
        // Use NTMLParser if available, otherwise basic parsing
        if (typeof NTMLParser !== 'undefined' && NTMLParser.parse) {
            const result = NTMLParser.parse(input);
            if (result && result.valid) {
                if (validFrom) result.data.valid_from = validFrom;
                if (validUntil) result.data.valid_until = validUntil;
                return result.data;
            }
            return null;
        }
        
        // Basic fallback parsing
        return {
            raw_input: input,
            entry_type: detectEntryType(input),
            valid_from: validFrom || '',
            valid_until: validUntil || ''
        };
    }
    
    /**
     * Detect entry type from input
     */
    function detectEntryType(input) {
        const upper = input.toUpperCase();
        if (upper.includes('MIT') && !upper.includes('MINIT')) return 'MIT';
        if (upper.includes('MINIT')) return 'MINIT';
        if (upper.includes('STOP')) return 'STOP';
        if (upper.includes('DELAY')) return 'DELAY';
        if (upper.includes('CONFIG')) return 'CONFIG';
        if (upper.includes('TBM')) return 'TBM';
        if (upper.includes('CFR')) return 'CFR';
        if (upper.includes('APREQ')) return 'APREQ';
        if (upper.includes('DSP')) return 'DSP';
        return 'MIT'; // Default
    }
    
    /**
     * Check for cross-border TMI
     */
    function checkCrossBorder(input) {
        const upper = input.toUpperCase();
        
        // Check for Canadian facilities/airports
        const hasCanadian = /\b(CZ[A-Z]{2}|C[A-Z]{3}|CYYZ|CYVR|CYYC|CYUL|CYWG)\b/.test(upper);
        
        // Check for US facilities near border
        const hasBorderUS = /\b(ZBW|ZMP|ZSE|ZLC|ZOB)\b/.test(upper);
        
        const isCrossBorder = hasCanadian && hasBorderUS;
        
        if (elements.crossBorderBadge) {
            elements.crossBorderBadge.classList.toggle('show', isCrossBorder);
        }
        
        // Auto-select VATCAN if cross-border detected and user is privileged
        if (isCrossBorder && config.isPrivileged) {
            const vatcanCheck = document.querySelector('.discord-org-check[data-org="vatcan"] input');
            if (vatcanCheck && !vatcanCheck.disabled && !vatcanCheck.checked) {
                vatcanCheck.checked = true;
                vatcanCheck.closest('.discord-org-check').classList.add('checked');
                updateSelectedOrgs();
            }
        }
    }
    
    /**
     * Update queue display
     */
    function updateQueueDisplay() {
        const count = entryQueue.length;
        
        if (elements.queueCount) {
            elements.queueCount.textContent = count;
        }
        
        if (elements.submitCount) {
            elements.submitCount.textContent = count;
        }
        
        if (elements.emptyQueueMsg) {
            elements.emptyQueueMsg.style.display = count === 0 ? 'block' : 'none';
        }
        
        if (elements.clearQueueBtn) {
            elements.clearQueueBtn.style.display = count > 0 ? 'inline-block' : 'none';
        }
        
        if (elements.ntmlSubmitArea) {
            elements.ntmlSubmitArea.style.display = count > 0 ? 'flex' : 'none';
        }
        
        // Render queue cards
        renderQueueCards();
    }
    
    /**
     * Render queue cards
     */
    function renderQueueCards() {
        if (!elements.entryQueue) return;
        
        const cards = entryQueue.map((entry, index) => {
            const type = entry.entry_type || 'MIT';
            const determinant = entry.determinant_code || generateDeterminant(type);
            const details = entry.raw_input || formatEntryDetails(entry);
            
            return `
                <div class="preview-card valid" data-index="${index}">
                    <button class="remove-btn" onclick="TMIPublisher.removeFromQueue(${index})">
                        <i class="fas fa-times"></i>
                    </button>
                    <div class="determinant">[${determinant}] ${type}</div>
                    <div class="details">${escapeHtml(details)}</div>
                    ${entry.valid_from || entry.valid_until ? 
                        `<div class="details text-muted">${entry.valid_from || '????'}Z - ${entry.valid_until || '????'}Z</div>` : ''}
                </div>
            `;
        }).join('');
        
        // Keep empty message, add cards before it
        const emptyMsg = elements.emptyQueueMsg.outerHTML;
        elements.entryQueue.innerHTML = cards + emptyMsg;
        
        // Re-cache empty msg element
        elements.emptyQueueMsg = document.getElementById('emptyQueueMsg');
        elements.emptyQueueMsg.style.display = entryQueue.length === 0 ? 'block' : 'none';
    }
    
    /**
     * Remove entry from queue
     */
    function removeFromQueue(index) {
        entryQueue.splice(index, 1);
        updateQueueDisplay();
    }
    
    /**
     * Clear entire queue
     */
    function clearQueue() {
        if (confirm('Clear all entries from queue?')) {
            entryQueue = [];
            updateQueueDisplay();
        }
    }
    
    /**
     * Generate determinant code
     */
    function generateDeterminant(type) {
        const now = new Date();
        const day = String(now.getUTCDate()).padStart(2, '0');
        const hour = String(now.getUTCHours()).padStart(2, '0');
        const min = String(now.getUTCMinutes()).padStart(2, '0');
        
        const typeCode = {
            'MIT': 'A', 'MINIT': 'B', 'STOP': 'C', 'DELAY': 'D',
            'CONFIG': 'E', 'TBM': 'F', 'CFR': 'G', 'APREQ': 'H'
        }[type] || 'A';
        
        return `${day}${typeCode}${hour}`;
    }
    
    /**
     * Format entry details for display
     */
    function formatEntryDetails(entry) {
        const parts = [];
        if (entry.distance) parts.push(`${entry.distance}${entry.entry_type}`);
        if (entry.from_facility) parts.push(entry.from_facility);
        if (entry.to_facility) parts.push(`→${entry.to_facility}`);
        if (entry.airport || entry.ctl_element) parts.push(entry.airport || entry.ctl_element);
        if (entry.condition_text || entry.via_route) parts.push(entry.condition_text || entry.via_route);
        if (entry.reason) parts.push(entry.reason);
        return parts.join(' ') || entry.raw_input || 'Entry';
    }
    
    /**
     * Show preview modal
     */
    function showPreview() {
        if (!elements.previewContent) return;
        
        const preview = entryQueue.map((entry, i) => {
            return `=== Entry ${i + 1} ===\n${formatNTMLMessage(entry)}`;
        }).join('\n\n');
        
        elements.previewContent.textContent = preview || 'No entries to preview';
        $('#previewModal').modal('show');
    }
    
    /**
     * Format NTML message (simplified - actual formatting done server-side)
     */
    function formatNTMLMessage(entry) {
        const lines = [];
        const det = entry.determinant_code || generateDeterminant(entry.entry_type);
        const now = new Date();
        const logTime = `${String(now.getUTCDate()).padStart(2, '0')}/${String(now.getUTCHours()).padStart(2, '0')}${String(now.getUTCMinutes()).padStart(2, '0')}`;
        
        lines.push(`${logTime}    ${entry.airport || entry.ctl_element || '???'} ${entry.entry_type || 'MIT'}`);
        if (entry.raw_input) {
            lines.push(entry.raw_input);
        }
        if (entry.valid_from || entry.valid_until) {
            lines.push(`${entry.valid_from || '????'}-${entry.valid_until || '????'}`);
        }
        
        return lines.join('\n');
    }
    
    /**
     * Apply template
     */
    function applyTemplate(template) {
        const templates = {
            'mit-arr': '20MIT ZBW→ZNY JFK LENDY VOLUME',
            'mit-dep': '15MIT JFK ZNY→ZDC VOLUME',
            'minit': '10MINIT ZOB→ZNY LGA WEATHER',
            'stop': 'STOP JFK WEATHER ZNY:ZDC,ZBW',
            'delay': 'DELAY JFK 45min INC 12flt WEATHER',
            'config': 'CONFIG JFK IMC ARR:22L/22R DEP:31L AAR:40 ADR:45',
            'cancel': 'CANCEL 05B01 MIT'
        };
        
        if (templates[template] && elements.quickInput) {
            elements.quickInput.value = templates[template];
            elements.quickInput.focus();
            elements.quickInput.select();
        }
    }
    
    /**
     * Submit all NTML entries
     */
    async function submitAllNTML() {
        if (isSubmitting || entryQueue.length === 0) return;
        
        if (selectedOrgs.length === 0) {
            alert('Please select at least one Discord organization to post to.');
            return;
        }
        
        if (currentMode === 'production') {
            if (!confirm('You are about to post to PRODUCTION Discord channels. Continue?')) {
                return;
            }
        }
        
        isSubmitting = true;
        if (elements.submitAllBtn) {
            elements.submitAllBtn.disabled = true;
            elements.submitAllBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
        }
        
        try {
            const response = await fetch('/api/mgt/tmi/publish.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    entry_type: 'NTML_BATCH',
                    entries: entryQueue,
                    targets: {
                        orgs: selectedOrgs,
                        staging: currentMode === 'staging',
                        production: currentMode === 'production'
                    },
                    source: 'PERTI',
                    user_cid: config.userCID,
                    user_name: config.userName
                })
            });
            
            const result = await response.json();
            
            if (result.success) {
                showPublishResults(result.results);
                entryQueue = [];
                updateQueueDisplay();
            } else {
                alert('Error publishing entries: ' + (result.error || 'Unknown error'));
            }
        } catch (error) {
            console.error('[TMIPublisher] Submit error:', error);
            alert('Error submitting entries. See console for details.');
        } finally {
            isSubmitting = false;
            if (elements.submitAllBtn) {
                elements.submitAllBtn.disabled = false;
                elements.submitAllBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit All to NTML';
            }
        }
    }
    
    /**
     * Show publish results
     */
    function showPublishResults(results) {
        if (!elements.publishResults || !elements.publishResultsList) return;
        
        const html = Object.entries(results).map(([orgCode, orgResult]) => {
            const isSuccess = orgResult.success;
            const statusIcon = isSuccess ? 'fa-check-circle text-success' : 'fa-times-circle text-danger';
            
            return `
                <div class="publish-result-item ${isSuccess ? 'success' : 'error'}">
                    <span class="org-badge">${orgCode.toUpperCase()}</span>
                    <i class="fas ${statusIcon} status-icon mr-2"></i>
                    <span class="flex-grow-1">
                        ${isSuccess ? 
                            `Posted successfully` : 
                            `Failed: ${orgResult.error || 'Unknown error'}`}
                    </span>
                    ${orgResult.message_url ? 
                        `<a href="${orgResult.message_url}" target="_blank" class="message-link">
                            <i class="fas fa-external-link-alt mr-1"></i>View
                        </a>` : ''}
                </div>
            `;
        }).join('');
        
        elements.publishResultsList.innerHTML = html;
        elements.publishResults.style.display = 'block';
        
        // Auto-hide after 30 seconds
        setTimeout(() => {
            elements.publishResults.style.display = 'none';
        }, 30000);
    }
    
    /**
     * Select advisory type
     */
    function selectAdvisoryType(type) {
        elements.advisoryTypeCards.forEach(card => {
            card.classList.toggle('selected', card.dataset.type === type);
        });
        
        // Delegate to AdvisoryBuilder if available
        if (typeof AdvisoryBuilder !== 'undefined' && AdvisoryBuilder.selectType) {
            AdvisoryBuilder.selectType(type);
        }
    }
    
    /**
     * Publish advisory
     */
    async function publishAdvisory() {
        if (isSubmitting) return;
        
        if (selectedOrgs.length === 0) {
            alert('Please select at least one Discord organization to post to.');
            return;
        }
        
        // Get advisory data from AdvisoryBuilder
        let advisoryData = {};
        if (typeof AdvisoryBuilder !== 'undefined' && AdvisoryBuilder.getData) {
            advisoryData = AdvisoryBuilder.getData();
        } else {
            // Fallback: get from preview
            advisoryData = {
                content: elements.advisoryPreview ? elements.advisoryPreview.textContent : ''
            };
        }
        
        if (!advisoryData.content && !advisoryData.type) {
            alert('Please complete the advisory form before publishing.');
            return;
        }
        
        if (currentMode === 'production') {
            if (!confirm('You are about to post to PRODUCTION Discord channels. Continue?')) {
                return;
            }
        }
        
        isSubmitting = true;
        if (elements.publishAdvisoryBtn) {
            elements.publishAdvisoryBtn.disabled = true;
            elements.publishAdvisoryBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Publishing...';
        }
        
        try {
            const response = await fetch('/api/mgt/tmi/publish.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    entry_type: 'ADVISORY',
                    data: advisoryData,
                    targets: {
                        orgs: selectedOrgs,
                        staging: currentMode === 'staging',
                        production: currentMode === 'production'
                    },
                    source: 'PERTI',
                    user_cid: config.userCID,
                    user_name: config.userName
                })
            });
            
            const result = await response.json();
            
            if (result.success) {
                showPublishResults(result.results);
            } else {
                alert('Error publishing advisory: ' + (result.error || 'Unknown error'));
            }
        } catch (error) {
            console.error('[TMIPublisher] Advisory publish error:', error);
            alert('Error publishing advisory. See console for details.');
        } finally {
            isSubmitting = false;
            if (elements.publishAdvisoryBtn) {
                elements.publishAdvisoryBtn.disabled = false;
                elements.publishAdvisoryBtn.innerHTML = '<i class="fas fa-paper-plane mr-1"></i> Publish Advisory';
            }
        }
    }
    
    /**
     * Copy advisory to clipboard
     */
    function copyAdvisoryToClipboard() {
        if (!elements.advisoryPreview) return;
        
        const text = elements.advisoryPreview.textContent;
        
        navigator.clipboard.writeText(text).then(() => {
            // Show feedback
            const originalText = elements.copyBtn.innerHTML;
            elements.copyBtn.innerHTML = '<i class="fas fa-check mr-1"></i> Copied!';
            setTimeout(() => {
                elements.copyBtn.innerHTML = originalText;
            }, 2000);
        }).catch(err => {
            console.error('Copy failed:', err);
            alert('Failed to copy to clipboard');
        });
    }
    
    /**
     * Start UTC clock
     */
    function startClock() {
        function updateClock() {
            const now = new Date();
            const utc = now.toISOString().substr(11, 8) + 'Z';
            
            if (elements.utcClock) {
                elements.utcClock.textContent = utc;
            }
            
            // Also update advisory builder clock if present
            const advClock = document.getElementById('adv_utc_clock');
            if (advClock) {
                advClock.textContent = utc;
            }
        }
        
        updateClock();
        setInterval(updateClock, 1000);
    }
    
    /**
     * Escape HTML
     */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // Public API
    return {
        init,
        setMode,
        removeFromQueue,
        clearQueue,
        getSelectedOrgs: () => selectedOrgs,
        getCurrentMode: () => currentMode,
        getEntryQueue: () => entryQueue
    };
    
})();

// Initialize on DOM ready
document.addEventListener('DOMContentLoaded', TMIPublisher.init);
