// Kopiuje wynik `nuxt generate` (.output/public) do katalogu crm2/ w korzeniu SZO — stamtąd serwuje go Apache pod /crm2/.
// Uruchamiaj przez `npm run build:szo`. Katalog crm2/ jest commitowany (serwer SZO robi tylko git pull, bez budowania).
import { cpSync, existsSync, rmSync, readdirSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const here = dirname(fileURLToPath(import.meta.url))
const src = resolve(here, '../.output/public')
const dst = resolve(here, '../../crm2')
if (!existsSync(join(src, 'index.html'))) {
  console.error('Brak .output/public/index.html — najpierw `nuxt generate`.')
  process.exit(1)
}
if ((process.env.NUXT_APP_BASE_URL || '/crm2/') !== '/crm2/') {
  console.error('Build dla SZO musi mieć baseURL /crm2/ (bez NUXT_APP_BASE_URL).')
  process.exit(1)
}
if (existsSync(dst)) {
  for (const f of readdirSync(dst)) rmSync(join(dst, f), { recursive: true, force: true })
}
cpSync(src, dst, { recursive: true })
console.log(`Opublikowano do ${dst}`)
