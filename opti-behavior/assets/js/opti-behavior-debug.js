/**
 * opti_behavior Heatmap Debug Utility
 * 
 * Centralized JavaScript debugging with configurable levels and formatting
 * 
 * @package opti_behavior_heatmap
 * @version 2.0.4
 */

(function() {
    'use strict';

    /**
     * opti_behavior Debug Logger
     */
    class OptiBehaviorDebug {
        constructor(config = {}) {
            this.config = {
                enabled: config.enabled || false,
                logLevel: config.logLevel || 'info',
                prefix: config.prefix || '[optibehavior]',
                timestamp: config.timestamp !== false,
                stackTrace: config.stackTrace || false,
                styles: {
                    error: 'color: #d63638; font-weight: bold;',
                    warning: 'color: #dba617; font-weight: bold;',
                    info: 'color: #2271b1;',
                    debug: 'color: #646970;',
                    success: 'color: #00a32a; font-weight: bold;'
                }
            };

            this.levels = {
                error: 1,
                warning: 2,
                info: 3,
                debug: 4
            };

            this.currentLevel = this.levels[this.config.logLevel] || 3;
        }

        /**
         * Check if logging is enabled for a level
         */
        shouldLog(level) {
            if (!this.config.enabled) return false;
            const messageLevel = this.levels[level] || 3;
            return messageLevel <= this.currentLevel;
        }

        /**
         * Format log message
         */
        formatMessage(message, context = '') {
            let formatted = this.config.prefix;
            
            if (this.config.timestamp) {
                const now = new Date();
                const time = now.toTimeString().split(' ')[0];
                formatted += ` [${time}]`;
            }

            if (context) {
                formatted += ` [${context}]`;
            }

            formatted += ` ${message}`;
            return formatted;
        }

        /**
         * Log error message
         */
        error(message, context = '', data = null) {
            if (!this.shouldLog('error')) return;

            const formatted = this.formatMessage(message, context);
            console.error(`%c${formatted}`, this.config.styles.error);
            
            if (data) {
                console.error('Data:', data);
            }

            if (this.config.stackTrace) {
                console.trace();
            }
        }

        /**
         * Log warning message
         */
        warning(message, context = '', data = null) {
            if (!this.shouldLog('warning')) return;

            const formatted = this.formatMessage(message, context);
            console.warn(`%c${formatted}`, this.config.styles.warning);
            
            if (data) {
                console.warn('Data:', data);
            }
        }

        /**
         * Log info message
         */
        info(message, context = '', data = null) {
            if (!this.shouldLog('info')) return;

            const formatted = this.formatMessage(message, context);
            console.log(`%c${formatted}`, this.config.styles.info);
            
            if (data) {
                console.log('Data:', data);
            }
        }

        /**
         * Log debug message
         */
        debug(message, context = '', data = null) {
            if (!this.shouldLog('debug')) return;

            const formatted = this.formatMessage(message, context);
            console.log(`%c${formatted}`, this.config.styles.debug);
            
            if (data) {
                console.log('Data:', data);
            }
        }

        /**
         * Log success message
         */
        success(message, context = '', data = null) {
            if (!this.shouldLog('info')) return;

            const formatted = this.formatMessage(message, context);
            console.log(`%c${formatted}`, this.config.styles.success);
            
            if (data) {
                console.log('Data:', data);
            }
        }

        /**
         * Group logs
         */
        group(title, collapsed = false) {
            if (!this.config.enabled) return;
            
            const formatted = this.formatMessage(title);
            if (collapsed) {
                console.groupCollapsed(formatted);
            } else {
                console.group(formatted);
            }
        }

        /**
         * End group
         */
        groupEnd() {
            if (!this.config.enabled) return;
            console.groupEnd();
        }

        /**
         * Log table data
         */
        table(data, context = '') {
            if (!this.shouldLog('debug')) return;

            if (context) {
                this.debug(`Table: ${context}`);
            }
            console.table(data);
        }

        /**
         * Time a function execution
         */
        time(label) {
            if (!this.config.enabled) return;
            console.time(this.config.prefix + ' ' + label);
        }

        /**
         * End timing
         */
        timeEnd(label) {
            if (!this.config.enabled) return;
            console.timeEnd(this.config.prefix + ' ' + label);
        }

        /**
         * Assert condition
         */
        assert(condition, message, context = '') {
            if (!this.config.enabled) return;
            
            if (!condition) {
                const formatted = this.formatMessage(message, context);
                console.assert(condition, formatted);
            }
        }

        /**
         * Count occurrences
         */
        count(label) {
            if (!this.config.enabled) return;
            console.count(this.config.prefix + ' ' + label);
        }

        /**
         * Reset count
         */
        countReset(label) {
            if (!this.config.enabled) return;
            console.countReset(this.config.prefix + ' ' + label);
        }

        /**
         * Enable debugging
         */
        enable() {
            this.config.enabled = true;
            this.success('Debug logging enabled');
        }

        /**
         * Disable debugging
         */
        disable() {
            this.info('Debug logging disabled');
            this.config.enabled = false;
        }

        /**
         * Set log level
         */
        setLevel(level) {
            if (this.levels[level]) {
                this.config.logLevel = level;
                this.currentLevel = this.levels[level];
                this.info(`Log level set to: ${level}`);
            }
        }

        /**
         * Get current configuration
         */
        getConfig() {
            return { ...this.config };
        }
    }

    // Initialize global debug instance
    window.OptiBehaviorDebug = window.OptiBehaviorDebug || null;

    /**
     * Initialize debug logger with server configuration
     */
    function initializeDebug() {
        // Get configuration from localized script data
        const config = window.opti_behaviorDebugConfig || {
            enabled: false,
            logLevel: 'info',
            prefix: '[optibehavior]',
            timestamp: true,
            stackTrace: false
        };

        window.OptiBehaviorDebug = new OptiBehaviorDebug(config);

        // Expose to global scope for easy access in console
        if (config.enabled) {
            window.OptiBehaviorDebug.info('Debug logger initialized', 'init', config);
        }
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeDebug);
    } else {
        initializeDebug();
    }

    // Fallback: create a no-op debug instance if not initialized
    if (!window.OptiBehaviorDebug) {
        window.OptiBehaviorDebug = new OptiBehaviorDebug({ enabled: false });
    }

})();

