/**
 * proxy.conf.js — dynamiczny proxy dla Angular dev server
 * API target czytany z env: KURSANT_API_TARGET (domyślnie szo.feer.org.pl)
 *
 *   KURSANT_API_TARGET=https://szo.feer.org.pl npm start
 *   KURSANT_API_TARGET=http://localhost:8080 npm start
 */
const target = process.env.KURSANT_API_TARGET || 'https://szo.feer.org.pl';

console.log(`[proxy] API target: ${target}`);

module.exports = [
  {
    context: ['/api'],
    target,
    secure: true,
    changeOrigin: true,
    logLevel: 'info',
  },
];
