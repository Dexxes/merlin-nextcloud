// Opens the Merlin web app like a user: log in, see a saved article in the
// list, open it in the reader, open its public share link logged out.
// Fails on uncaught JavaScript errors of the app.
import { test, expect } from '@playwright/test'

const user = process.env.MERLIN_TEST_ADMIN_USER || 'admin'
const password = process.env.MERLIN_TEST_ADMIN_PASSWORD || 'admin'
const auth = 'Basic ' + Buffer.from(`${user}:${password}`).toString('base64')
const api = (path) => `/index.php/apps/merlin${path}`
const title = `E2E-Artikel ${Date.now()}`

let articleId
let shareUrl

test.beforeAll(async ({ request }) => {
	const headers = { Authorization: auth, 'OCS-APIRequest': 'true' }
	const html = `<html><head><title>${title}</title></head><body><article><h1>${title}</h1>`
		+ '<p>Erster Absatz des Browsertests mit genug Text, damit die Extraktion ihn als Artikel erkennt.</p>'
		+ '<p>Zweiter Absatz des Browsertests, der im Reader sichtbar sein muss, Lorem ipsum dolor sit amet.</p>'
		+ '</article></body></html>'
	const added = await request.post(api('/api/v1/add'), {
		headers,
		data: { url: `https://example.org/merlin-e2e/${Date.now()}`, title, html },
	})
	expect([200, 201]).toContain(added.status())
	articleId = Number((await added.json()).item.item_id)

	await expect.poll(async () => {
		const res = await request.get(api(`/api/articles/${articleId}`), { headers })
		return (await res.json()).isProcessing
	}, { timeout: 60_000 }).toBe(false)

	const share = await request.post(api(`/api/articles/${articleId}/share`), { headers, data: {} })
	expect([200, 201]).toContain(share.status())
	shareUrl = (await share.json()).url
})

test.afterAll(async ({ request }) => {
	if (articleId) {
		await request.delete(api(`/api/articles/${articleId}`), { headers: { Authorization: auth, 'OCS-APIRequest': 'true' } })
	}
})

function collectErrors(page) {
	const errors = []
	page.on('pageerror', (error) => errors.push(error.message))
	return errors
}

test('log in, list and read an article', async ({ page }) => {
	const errors = collectErrors(page)

	await page.goto('/index.php/login')
	await page.locator('input[name="user"]').fill(user)
	await page.locator('input[name="password"]').fill(password)
	await page.locator('button[type="submit"], input[type="submit"]').first().click()
	await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 60_000 })

	await page.goto('/index.php/apps/merlin/')
	await expect(page.locator('#merlin-app')).toBeAttached()
	const card = page.getByText(title).first()
	await expect(card).toBeVisible()

	await card.click()
	await expect(page.getByText('Zweiter Absatz des Browsertests').first()).toBeVisible()

	expect(errors, 'uncaught JavaScript errors').toEqual([])
})

test('public share link works logged out', async ({ browser }) => {
	const context = await browser.newContext()
	const page = await context.newPage()
	const errors = collectErrors(page)

	await page.goto(shareUrl)
	await expect(page.getByText('Zweiter Absatz des Browsertests').first()).toBeVisible()

	expect(errors, 'uncaught JavaScript errors').toEqual([])
	await context.close()
})
