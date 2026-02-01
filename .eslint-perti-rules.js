/**
 * Custom ESLint rules for PERTI codebase
 *
 * Install with:
 *   npm install eslint-plugin-local-rules --save-dev
 *
 * Add to .eslintrc.json:
 *   "plugins": ["local-rules"],
 *   "rules": { "local-rules/no-substr": "error", ... }
 */

module.exports = {
    rules: {
        /**
         * Disallow .substr() - use .slice() instead
         */
        'no-substr': {
            meta: {
                type: 'suggestion',
                docs: {
                    description: 'Disallow .substr() in favor of .slice()',
                    category: 'Best Practices',
                },
                fixable: null,
                messages: {
                    noSubstr: 'Use .slice(start, end) instead of .substr(start, length). Convert: .substr({{start}}, {{len}}) -> .slice({{start}}, {{end}})',
                },
            },
            create(context) {
                return {
                    CallExpression(node) {
                        if (
                            node.callee.type === 'MemberExpression' &&
                            node.callee.property.name === 'substr'
                        ) {
                            const args = node.arguments;
                            let start = '?';
                            let len = '?';
                            let end = '?';

                            if (args[0] && args[0].type === 'Literal') {
                                start = args[0].value;
                            }
                            if (args[1] && args[1].type === 'Literal') {
                                len = args[1].value;
                                if (typeof start === 'number' && typeof len === 'number') {
                                    end = start + len;
                                }
                            }

                            context.report({
                                node: node.callee.property,
                                messageId: 'noSubstr',
                                data: { start, len, end },
                            });
                        }
                    },
                };
            },
        },

        /**
         * Disallow hardcoded hex colors - use PERTIColors
         */
        'no-hardcoded-colors': {
            meta: {
                type: 'suggestion',
                docs: {
                    description: 'Disallow hardcoded hex colors, use PERTIColors instead',
                    category: 'Best Practices',
                },
                messages: {
                    noHardcodedColor: 'Use PERTIColors instead of hardcoded hex: {{color}}',
                },
            },
            create(context) {
                const hexPattern = /^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/;

                return {
                    Literal(node) {
                        if (
                            typeof node.value === 'string' &&
                            hexPattern.test(node.value)
                        ) {
                            // Ignore in config files
                            const filename = context.getFilename();
                            if (
                                filename.includes('colors.js') ||
                                filename.includes('config/')
                            ) {
                                return;
                            }

                            context.report({
                                node,
                                messageId: 'noHardcodedColor',
                                data: { color: node.value },
                            });
                        }
                    },
                };
            },
        },

        /**
         * Disallow raw console.log - use PERTILogger
         */
        'use-perti-logger': {
            meta: {
                type: 'suggestion',
                docs: {
                    description: 'Use PERTILogger instead of console methods',
                    category: 'Best Practices',
                },
                messages: {
                    useLogger: 'Use PERTILogger.{{method}}() instead of console.{{method}}()',
                },
            },
            create(context) {
                return {
                    CallExpression(node) {
                        if (
                            node.callee.type === 'MemberExpression' &&
                            node.callee.object.name === 'console' &&
                            ['log', 'info', 'debug', 'table'].includes(node.callee.property.name)
                        ) {
                            // Ignore in server-side scripts
                            const filename = context.getFilename();
                            if (
                                filename.includes('discord-bot') ||
                                filename.includes('simulator') ||
                                filename.includes('scripts/')
                            ) {
                                return;
                            }

                            context.report({
                                node,
                                messageId: 'useLogger',
                                data: { method: node.callee.property.name },
                            });
                        }
                    },
                };
            },
        },

        /**
         * Disallow raw Date formatting - use PERTIDateTime
         */
        'use-perti-datetime': {
            meta: {
                type: 'suggestion',
                docs: {
                    description: 'Use PERTIDateTime for date formatting',
                    category: 'Best Practices',
                },
                messages: {
                    useDateTime: 'Use PERTIDateTime formatting functions instead of manual Date manipulation',
                },
            },
            create(context) {
                return {
                    CallExpression(node) {
                        // Check for .toISOString().slice() or .toISOString().substr()
                        if (
                            node.callee.type === 'MemberExpression' &&
                            (node.callee.property.name === 'slice' || node.callee.property.name === 'substr')
                        ) {
                            const obj = node.callee.object;
                            if (
                                obj.type === 'CallExpression' &&
                                obj.callee.type === 'MemberExpression' &&
                                obj.callee.property.name === 'toISOString'
                            ) {
                                context.report({
                                    node,
                                    messageId: 'useDateTime',
                                });
                            }
                        }
                    },
                };
            },
        },

        /**
         * Disallow empty catch blocks
         */
        'no-empty-catch': {
            meta: {
                type: 'problem',
                docs: {
                    description: 'Disallow empty catch blocks without error handling',
                    category: 'Best Practices',
                },
                messages: {
                    noEmptyCatch: 'Empty catch block. Add error handling or logging: PERTILogger.warn(\'context\', e)',
                },
            },
            create(context) {
                return {
                    CatchClause(node) {
                        if (
                            node.body.body.length === 0 ||
                            (node.body.body.length === 1 &&
                                node.body.body[0].type === 'EmptyStatement')
                        ) {
                            context.report({
                                node,
                                messageId: 'noEmptyCatch',
                            });
                        }
                    },
                };
            },
        },
    },
};
