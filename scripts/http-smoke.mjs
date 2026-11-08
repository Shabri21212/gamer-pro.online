import assert from 'node:assert/strict'

const base = process.argv[2] || 'http://127.0.0.1:3001'
assert.ok(['localhost', '127.0.0.1'].includes(new URL(base).hostname), 'Tests must target an isolated local fixture')

async function session(login) {
  let cookie = ''
  const request = async (path, options = {}) => {
    const response = await fetch(base + path, { ...options, redirect: 'manual', headers: { Cookie: cookie, ...options.headers } })
    for (const value of response.headers.getSetCookie()) {
      if (value.startsWith('gamerpro=')) cookie = value.split(';')[0]
    }
    const body = await response.text()
    return { response, body }
  }
  const { body } = await request('/sign-in')
  const csrf = body.match(/name="_csrf" value="([^"]+)"/)[1]
  const result = await request('/sign-in', { method: 'POST', body: new URLSearchParams({ _action: 'login', _csrf: csrf, login, password: 'Only-Test-2026!' }) })
  assert.equal(result.response.status, 303)
  assert.equal(result.response.headers.get('location'), '/')
  return request
}
const owner = await session('test-owner')
for (const path of ['/', '/schedule', '/loyalty', '/manage/payroll', '/manage/grades']) {
  const { response, body } = await owner(path)
  assert.equal(response.status, 200, path)
  assert.match(body, /<main/, path)
  assert.doesNotMatch(body, /Fatal error|Warning:/, path)
  console.log('PASS owner GET', path)
}
const csv = await owner('/manage/payroll?m=2026-11&export=csv')
assert.equal(csv.response.status, 200)
assert.match(csv.response.headers.get('content-type'), /text\/csv/)
assert.match(csv.body, /Сотрудник/)
console.log('PASS owner CSV export')
const manager = await session('test-manager')
for (const path of ['/manage/payroll', '/manage/payroll?export=csv']) {
  const { response, body } = await manager(path)
  assert.equal(response.status, 403)
  assert.match(body, /только владельцу/)
  console.log('PASS manager forbidden', path)
}
const { body: managerPage } = await manager('/')
assert.doesNotMatch(managerPage, /href="\/manage\/payroll/)
const token = managerPage.match(/name="_csrf" value="([^"]+)"/)[1]
const denied = await manager('/manage/payroll', { method: 'POST', body: new URLSearchParams({ _action: 'save_payroll', _csrf: token, employeeId: '1', month: '2026-11', percent: '99' }) })
assert.equal(denied.response.status, 403)
console.log('PASS manager cannot POST payroll')
const noCsrf = await owner('/manage/payroll', { method: 'POST', body: new URLSearchParams({ _action: 'save_payroll', employeeId: '1', month: '2026-11', percent: '99' }) })
assert.equal(noCsrf.response.status, 303)
console.log('PASS missing CSRF rejected')
for (const path of ['/storage/dev.sqlite', '/database/localhost.sql', '/src/bootstrap.php', '/package.json']) {
  assert.equal((await fetch(base + path, { redirect: 'manual' })).status, 404)
  console.log('PASS private file blocked', path)
}
console.log('HTTP and permission checks passed.')
