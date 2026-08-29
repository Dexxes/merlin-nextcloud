import { createStore } from 'vuex'
import { generateUrl } from '@nextcloud/router'
import * as articlesAPI from '../api/articles'
import * as tagsAPI from '../api/tags'
import * as settingsAPI from '../api/settings'

// Module-level EventSource so we can close any existing connection before
// opening a new one.  Kept outside the store to avoid reactivity overhead.
let _sseSource = null
// Fallback polling timer – only active when SSE fails to connect.
let _processingPollTimer = null



export default createStore({
	state: {
		articles: [],
		counts: {
			pages: { total: 0, unread: 0, favorites: 0, archived: 0 },
			videos: { total: 0, unread: 0, favorites: 0, archived: 0 },
		},
		tags: [],
		settings: {},
		currentArticle: null,
		loading: false,
		filter: {
			isRead: false,
			isFavorite: null,
			isArchived: false,
			tagId: null,
			category: null,
			contentType: 'page',
			search: '',
		},
		view: 'list', // 'list' or 'reader'
	},

	mutations: {
		SET_ARTICLES(state, articles) {
			state.articles = articles
		},
		SET_COUNTS(state, counts) {
			state.counts = counts
		},
		ADD_ARTICLE(state, article) {
			state.articles.unshift(article)
		},
		UPDATE_ARTICLE(state, updatedArticle) {
			const index = state.articles.findIndex(a => a.id === updatedArticle.id)
			if (index !== -1) {
				state.articles.splice(index, 1, updatedArticle)
			}
		},
		REMOVE_ARTICLE(state, articleId) {
			state.articles = state.articles.filter(a => a.id !== articleId)
		},
		SET_TAGS(state, tags) {
			state.tags = tags
		},
		ADD_TAG(state, tag) {
			state.tags.push(tag)
		},
		REMOVE_TAG(state, tagId) {
			state.tags = state.tags.filter(t => t.id !== tagId)
		},
		SET_SETTINGS(state, settings) {
			state.settings = settings
		},
		SET_CURRENT_ARTICLE(state, article) {
			state.currentArticle = article
		},
		SET_LOADING(state, loading) {
			state.loading = loading
		},
		SET_FILTER(state, { key, value }) {
			state.filter[key] = value
		},
		RESET_FILTER(state) {
			state.filter = {
				isRead: null,
				isFavorite: null,
				isArchived: false,
				tagId: null,
				category: null,
				contentType: null,
				search: '',
			}
		},
		SET_VIEW(state, view) {
			state.view = view
		},
	},

	actions: {
		async fetchCounts({ commit }) {
			try {
				const counts = await articlesAPI.getCounts()
				commit('SET_COUNTS', counts)
			} catch (error) {
				console.error('Failed to fetch counts:', error)
			}
		},

		async fetchArticles({ commit, state, dispatch }) {
			commit('SET_LOADING', true)
			try {
				const articles = await articlesAPI.getArticles(state.filter)
				commit('SET_ARTICLES', articles)
				// Start SSE only if articles are still processing AND no connection
				// is already open.  The _sseSource guard prevents a re-open loop
				// when this is called after an SSE close.
				if (articles.some(a => a.isProcessing) && !_sseSource) {
					dispatch('startSSE')
				}
			} catch (error) {
				console.error('Failed to fetch articles:', error)
			} finally {
				commit('SET_LOADING', false)
			}
		},

		async addArticle({ commit, dispatch }, url) {
			commit('SET_LOADING', true)
			try {
				const article = await articlesAPI.createArticle(url)
				commit('ADD_ARTICLE', article)
				dispatch('fetchCounts')
				// Article is processing – open SSE to receive the ready event.
				dispatch('startSSE')
				return article
			} catch (error) {
				console.error('Failed to add article:', error)
				throw error
			} finally {
				commit('SET_LOADING', false)
			}
		},

		/**
		 * Open a Server-Sent Events connection to /api/events.
		 * The server pushes one `article-ready` event per article that
		 * finishes processing and then closes the connection.
		 * The client never polls – it just reacts to incoming events.
		 */
		startSSE({ commit, state, dispatch }) {
			if (typeof EventSource === 'undefined') return

			// Replace any existing connection.
			if (_sseSource) {
				_sseSource.close()
				_sseSource = null
			}

			const url = generateUrl('/apps/merlin/api/events')
			_sseSource = new EventSource(url)

			_sseSource.addEventListener('article-ready', (event) => {
				// SSE is working — stop the fallback polling timer if it was running.
				if (_processingPollTimer) {
					clearInterval(_processingPollTimer)
					_processingPollTimer = null
				}
				try {
					const article = JSON.parse(event.data)
					commit('UPDATE_ARTICLE', article)
				} catch (e) {
					console.warn('Merlin SSE: could not parse article-ready payload', e)
				}
			})

			// When the SSE connection closes normally, silently refresh any
			// articles still marked as processing.  Also stop the fallback timer
			// (if it was started by onerror) to prevent redundant requests.
			const closeAndRefreshPending = () => {
				if (_sseSource) {
					_sseSource.close()
					_sseSource = null
				}
				if (_processingPollTimer) {
					clearInterval(_processingPollTimer)
					_processingPollTimer = null
				}
				dispatch('refreshProcessingArticles')
			}

			_sseSource.onmessage = (event) => {
				try {
					const msg = JSON.parse(event.data)
					if (msg.type === 'done' || msg.type === 'idle') {
						closeAndRefreshPending()
					}
				} catch (e) { /* ignore non-JSON heartbeat comments */ }
			}

			// When SSE fails (e.g. server-side error, proxy timeout), start a
			// polling interval as a fallback.  A single interval is created
			// (_processingPollTimer guard) and it stops itself automatically
			// once all processing articles have been updated.  This does NOT
			// replace SET_ARTICLES, so there is no loading flash.
			_sseSource.onerror = () => {
				if (_processingPollTimer) return // already polling
				_processingPollTimer = setInterval(async () => {
					await dispatch('refreshProcessingArticles')
					if (!state.articles.some(a => a.isProcessing)) {
						clearInterval(_processingPollTimer)
						_processingPollTimer = null
					}
				}, 3000)
			}
		},

		/**
		 * Silently fetch only the articles that are currently marked
		 * isProcessing in the local store and update them individually.
		 * Does NOT set the loading flag, does NOT replace the full list.
		 */
		async refreshProcessingArticles({ state, commit }) {
			const ids = state.articles.filter(a => a.isProcessing).map(a => a.id)
			if (!ids.length) return
			for (const id of ids) {
				try {
					const article = await articlesAPI.getArticle(id)
					commit('UPDATE_ARTICLE', article)
				} catch (e) {
					// Article may have been deleted – ignore
				}
			}
		},

		async toggleRead({ commit, dispatch }, articleId) {
			try {
				const result = await articlesAPI.toggleRead(articleId)
				const article = await articlesAPI.getArticle(articleId)
				commit('UPDATE_ARTICLE', article)
				dispatch('fetchCounts')
				return result
			} catch (error) {
				console.error('Failed to toggle read:', error)
				throw error
			}
		},

		async toggleArchive({ commit, dispatch, state }, articleId) {
			try {
				const result = await articlesAPI.toggleArchive(articleId)
				const article = await articlesAPI.getArticle(articleId)
				// Wenn der neue Archiv-Status nicht zum aktiven Filter passt,
				// Artikel sofort aus der Liste entfernen (z. B. beim Archivieren
				// in der Inbox-Ansicht oder beim Wiederherstellen in der Archiv-Ansicht).
				const filterIsArchived = state.filter.isArchived
				if (filterIsArchived !== null && article.isArchived !== filterIsArchived) {
					commit('REMOVE_ARTICLE', articleId)
				} else {
					commit('UPDATE_ARTICLE', article)
				}
				dispatch('fetchCounts')
				return result
			} catch (error) {
				console.error('Failed to toggle archive:', error)
				throw error
			}
		},

		async toggleFavorite({ commit, dispatch }, articleId) {
			try {
				const result = await articlesAPI.toggleFavorite(articleId)
				const article = await articlesAPI.getArticle(articleId)
				commit('UPDATE_ARTICLE', article)
				dispatch('fetchCounts')
				return result
			} catch (error) {
				console.error('Failed to toggle favorite:', error)
				throw error
			}
		},

		async deleteArticle({ commit, dispatch }, articleId) {
			try {
				await articlesAPI.deleteArticle(articleId)
				commit('REMOVE_ARTICLE', articleId)
				dispatch('fetchCounts')
			} catch (error) {
				console.error('Failed to delete article:', error)
				throw error
			}
		},

		async addTagToArticle({ commit }, { articleId, tagId }) {
			try {
				await tagsAPI.addTagToArticle(articleId, tagId)
				const article = await articlesAPI.getArticle(articleId)
				commit('UPDATE_ARTICLE', article)
			} catch (error) {
				console.error('Failed to add tag to article:', error)
				throw error
			}
		},

		async removeTagFromArticle({ commit }, { articleId, tagId }) {
			try {
				await tagsAPI.removeTagFromArticle(articleId, tagId)
				const article = await articlesAPI.getArticle(articleId)
				commit('UPDATE_ARTICLE', article)
			} catch (error) {
				console.error('Failed to remove tag from article:', error)
				throw error
			}
		},

		async fetchTags({ commit }) {
			try {
				const tags = await tagsAPI.getTags()
				commit('SET_TAGS', tags)
			} catch (error) {
				console.error('Failed to fetch tags:', error)
			}
		},

		async addTag({ commit }, tagData) {
			try {
				const tag = await tagsAPI.createTag(tagData)
				commit('ADD_TAG', tag)
				return tag
			} catch (error) {
				console.error('Failed to add tag:', error)
				throw error
			}
		},

		async deleteTag({ commit }, tagId) {
			try {
				await tagsAPI.deleteTag(tagId)
				commit('REMOVE_TAG', tagId)
			} catch (error) {
				console.error('Failed to delete tag:', error)
				throw error
			}
		},

		async fetchSettings({ commit }) {
			try {
				const settings = await settingsAPI.getSettings()
				commit('SET_SETTINGS', settings)
			} catch (error) {
				console.error('Failed to fetch settings:', error)
			}
		},

		async updateSettings({ commit }, settings) {
			try {
				const response = await settingsAPI.updateSettings(settings)
				// Den vom Server kanonisch typisierten Stand committen, nicht die rohen
				// Client-Typen: sonst weicht state.settings vom Ergebnis eines fetchSettings()/
				// pollForUpdates() ab (String vs. Number/Boolean) und JSON.stringify ist beim
				// nächsten Poll garantiert ungleich, was einen unnötigen SET_SETTINGS-Zyklus
				// auslöst (siehe SettingsController::update()).
				commit('SET_SETTINGS', { ...settings, ...(response && response.settings) })
			} catch (error) {
				console.error('Failed to update settings:', error)
				throw error
			}
		},

		async pollForUpdates({ state, commit }) {
			try {
				const counts = await articlesAPI.getCounts()
				const total = counts.pages.total + counts.videos.total
				const previousTotal = state.counts.pages.total + state.counts.videos.total
				if (total !== previousTotal) {
					commit('SET_COUNTS', counts)
					if (state.view === 'list') {
						const articles = await articlesAPI.getArticles(state.filter)
						commit('SET_ARTICLES', articles)
					}
				}

				// Settings (z.B. accentColor, progressEdge) werden auch von den
				// Mobile-Apps geändert. fetchSettings läuft sonst nur einmal beim
				// App-Start, d.h. eine offen gelassene Browser-Tab würde Änderungen
				// vom iPhone/Android nie sehen, bis manuell neu geladen wird.
				const settings = await settingsAPI.getSettings()
				if (JSON.stringify(settings) !== JSON.stringify(state.settings)) {
					commit('SET_SETTINGS', settings)
				}
			} catch {
				// silently ignore network errors during background poll
			}
		},

		async searchArticles({ commit }, query) {
			commit('SET_LOADING', true)
			try {
				const articles = await articlesAPI.searchArticles(query)
				commit('SET_ARTICLES', articles)
			} catch (error) {
				console.error('Failed to search articles:', error)
			} finally {
				commit('SET_LOADING', false)
			}
		},
	},

	getters: {
		// Blendet Artikel aus, die mindestens einen der in den Settings
		// ausgeschlossenen Tags tragen (excludedTagIds, analog zu iOS/Android).
		// Das Setting kommt vom Backend als JSON-String (siehe SettingsController.php),
		// daher hier defensiv parsen statt anzunehmen, dass es schon ein Array ist.
		excludedTagIdSet: state => {
			const raw = state.settings && state.settings.excludedTagIds
			if (Array.isArray(raw)) return new Set(raw)
			if (typeof raw === 'string' && raw.length) {
				try {
					const parsed = JSON.parse(raw)
					if (Array.isArray(parsed)) return new Set(parsed)
				} catch (e) {
					// malformed setting value – treat as "no exclusions" rather than crash
				}
			}
			return new Set()
		},
		filteredArticles: (state, getters) => {
			const excluded = getters.excludedTagIdSet
			if (excluded.size === 0) return state.articles
			return state.articles.filter(article => {
				const tags = article.tags || []
				return !tags.some(tag => excluded.has(tag.id))
			})
		},
		unreadCount: state => {
			return (state.articles || []).filter(a => !a.isRead && !a.isArchived).length
		},
		favoriteCount: state => {
			return (state.articles || []).filter(a => a.isFavorite).length
		},
	},
})
