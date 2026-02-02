/**
 * PERTI Dialog Utilities
 *
 * Wrapper around SweetAlert2 (Swal) with i18n support.
 * Use these functions instead of raw Swal.fire() for user-facing dialogs.
 *
 * Usage:
 *   PERTIDialog.success('dialog.saved.title', 'dialog.saved.text')
 *   PERTIDialog.confirm('dialog.delete.title', 'dialog.delete.text').then(...)
 *   PERTIDialog.show({ titleKey: 'dialog.title', textKey: 'dialog.text' })
 *
 * @module lib/dialog
 * @version 1.0.0
 * @requires SweetAlert2 (Swal)
 * @requires lib/i18n (PERTII18n)
 */

const PERTIDialog = (function() {
    'use strict';

    /**
     * Show a dialog with i18n support
     * Accepts either i18n keys (*Key properties) or direct strings
     *
     * @param {Object} options - Swal.fire options with additional *Key properties
     * @param {string} [options.titleKey] - i18n key for title
     * @param {Object} [options.titleParams] - Parameters for title interpolation
     * @param {string} [options.textKey] - i18n key for text
     * @param {Object} [options.textParams] - Parameters for text interpolation
     * @param {string} [options.htmlKey] - i18n key for html content
     * @param {Object} [options.htmlParams] - Parameters for html interpolation
     * @param {string} [options.confirmKey] - i18n key for confirm button
     * @param {string} [options.cancelKey] - i18n key for cancel button
     * @param {string} [options.denyKey] - i18n key for deny button
     * @returns {Promise} Swal.fire promise
     */
    function show(options) {
        if (typeof Swal === 'undefined') {
            console.error('[PERTIDialog] SweetAlert2 (Swal) is not loaded');
            return Promise.reject(new Error('Swal not loaded'));
        }

        const resolved = { ...options };

        // Resolve i18n keys to strings
        if (options.titleKey) {
            resolved.title = PERTII18n.t(options.titleKey, options.titleParams);
            delete resolved.titleKey;
            delete resolved.titleParams;
        }

        if (options.textKey) {
            resolved.text = PERTII18n.t(options.textKey, options.textParams);
            delete resolved.textKey;
            delete resolved.textParams;
        }

        if (options.htmlKey) {
            resolved.html = PERTII18n.t(options.htmlKey, options.htmlParams);
            delete resolved.htmlKey;
            delete resolved.htmlParams;
        }

        if (options.confirmKey) {
            resolved.confirmButtonText = PERTII18n.t(options.confirmKey);
            delete resolved.confirmKey;
        } else if (!options.confirmButtonText && options.showConfirmButton !== false) {
            resolved.confirmButtonText = PERTII18n.t('common.ok');
        }

        if (options.cancelKey) {
            resolved.cancelButtonText = PERTII18n.t(options.cancelKey);
            delete resolved.cancelKey;
        } else if (options.showCancelButton && !options.cancelButtonText) {
            resolved.cancelButtonText = PERTII18n.t('common.cancel');
        }

        if (options.denyKey) {
            resolved.denyButtonText = PERTII18n.t(options.denyKey);
            delete resolved.denyKey;
        }

        return Swal.fire(resolved);
    }

    /**
     * Show a success dialog
     * @param {string} titleKey - i18n key for title
     * @param {string} [textKey] - i18n key for text
     * @param {Object} [params] - Parameters for interpolation
     * @param {Object} [options] - Additional Swal options
     * @returns {Promise}
     */
    function success(titleKey, textKey, params = {}, options = {}) {
        return show({
            icon: 'success',
            titleKey,
            titleParams: params,
            textKey,
            textParams: params,
            timer: 2000,
            showConfirmButton: false,
            ...options,
        });
    }

    /**
     * Show an error dialog
     * @param {string} titleKey - i18n key for title
     * @param {string} [textKey] - i18n key for text
     * @param {Object} [params] - Parameters for interpolation
     * @param {Object} [options] - Additional Swal options
     * @returns {Promise}
     */
    function error(titleKey, textKey, params = {}, options = {}) {
        return show({
            icon: 'error',
            titleKey,
            titleParams: params,
            textKey,
            textParams: params,
            ...options,
        });
    }

    /**
     * Show a warning dialog
     * @param {string} titleKey - i18n key for title
     * @param {string} [textKey] - i18n key for text
     * @param {Object} [params] - Parameters for interpolation
     * @param {Object} [options] - Additional Swal options
     * @returns {Promise}
     */
    function warning(titleKey, textKey, params = {}, options = {}) {
        return show({
            icon: 'warning',
            titleKey,
            titleParams: params,
            textKey,
            textParams: params,
            ...options,
        });
    }

    /**
     * Show an info dialog
     * @param {string} titleKey - i18n key for title
     * @param {string} [textKey] - i18n key for text
     * @param {Object} [params] - Parameters for interpolation
     * @param {Object} [options] - Additional Swal options
     * @returns {Promise}
     */
    function info(titleKey, textKey, params = {}, options = {}) {
        return show({
            icon: 'info',
            titleKey,
            titleParams: params,
            textKey,
            textParams: params,
            ...options,
        });
    }

    /**
     * Show a confirmation dialog
     * @param {string} titleKey - i18n key for title
     * @param {string} [textKey] - i18n key for text
     * @param {Object} [params] - Parameters for interpolation
     * @param {Object} [options] - Additional Swal options
     * @returns {Promise} Resolves with { isConfirmed: boolean }
     */
    function confirm(titleKey, textKey, params = {}, options = {}) {
        return show({
            icon: 'question',
            titleKey,
            titleParams: params,
            textKey,
            textParams: params,
            showCancelButton: true,
            confirmKey: options.confirmKey || 'common.confirm',
            cancelKey: options.cancelKey || 'common.cancel',
            ...options,
        });
    }

    /**
     * Show a dangerous action confirmation (red confirm button)
     * @param {string} titleKey - i18n key for title
     * @param {string} [textKey] - i18n key for text
     * @param {Object} [params] - Parameters for interpolation
     * @param {Object} [options] - Additional Swal options
     * @returns {Promise}
     */
    function confirmDanger(titleKey, textKey, params = {}, options = {}) {
        return show({
            icon: 'warning',
            titleKey,
            titleParams: params,
            textKey,
            textParams: params,
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            confirmKey: options.confirmKey || 'common.yesDelete',
            cancelKey: options.cancelKey || 'common.cancel',
            ...options,
        });
    }

    /**
     * Show a loading dialog (call Swal.close() when done)
     * @param {string} [titleKey='common.loading'] - i18n key for title
     * @param {Object} [options] - Additional Swal options
     * @returns {Promise}
     */
    function loading(titleKey = 'common.loading', options = {}) {
        return show({
            titleKey,
            allowOutsideClick: false,
            allowEscapeKey: false,
            showConfirmButton: false,
            didOpen: () => {
                Swal.showLoading();
            },
            ...options,
        });
    }

    /**
     * Close any open dialog
     */
    function close() {
        if (typeof Swal !== 'undefined') {
            Swal.close();
        }
    }

    /**
     * Show a toast notification
     * @param {string} titleKey - i18n key for title
     * @param {string} [icon='success'] - Icon type
     * @param {Object} [params] - Parameters for interpolation
     * @param {Object} [options] - Additional Swal options
     * @returns {Promise}
     */
    function toast(titleKey, icon = 'success', params = {}, options = {}) {
        return show({
            toast: true,
            position: 'top-end',
            icon,
            titleKey,
            titleParams: params,
            showConfirmButton: false,
            timer: 3000,
            timerProgressBar: true,
            ...options,
        });
    }

    // Public API
    return {
        show,
        success,
        error,
        warning,
        info,
        confirm,
        confirmDanger,
        loading,
        close,
        toast,
    };
})();

// Export for ES modules if available
if (typeof module !== 'undefined' && module.exports) {
    module.exports = PERTIDialog;
}
