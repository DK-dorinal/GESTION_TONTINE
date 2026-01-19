// sw.js - Service Worker
const CACHE_NAME = 'tontine-app-v1.0';
const STATIC_CACHE_NAME = 'tontine-static-v1.0';
const DYNAMIC_CACHE_NAME = 'tontine-dynamic-v1.0';

// Assets à mettre en cache immédiatement
const STATIC_ASSETS = [
  '/',
  '/index.php',
  '/dashboard.php',
  '/credit.php',
  '/manifest.json',
  '/offline.html',
  '/css/style.css',
  'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css',
  'https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap',
  'https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap'
];

// Installer le Service Worker
self.addEventListener('install', (event) => {
  console.log('[Service Worker] Installation...');
  
  event.waitUntil(
    caches.open(STATIC_CACHE_NAME)
      .then((cache) => {
        console.log('[Service Worker] Mise en cache des assets statiques');
        return cache.addAll(STATIC_ASSETS);
      })
      .then(() => {
        console.log('[Service Worker] Installation terminée');
        return self.skipWaiting();
      })
  );
});

// Activer le Service Worker
self.addEventListener('activate', (event) => {
  console.log('[Service Worker] Activation...');
  
  event.waitUntil(
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames.map((cacheName) => {
          if (cacheName !== STATIC_CACHE_NAME && cacheName !== DYNAMIC_CACHE_NAME) {
            console.log('[Service Worker] Suppression de l\'ancien cache:', cacheName);
            return caches.delete(cacheName);
          }
        })
      );
    }).then(() => {
      console.log('[Service Worker] Activation terminée');
      return self.clients.claim();
    })
  );
});

// Stratégie de cache: Cache First, Network Fallback
self.addEventListener('fetch', (event) => {
  // Ignorer les requêtes POST et les appels API sensibles
  if (event.request.method !== 'GET' || 
      event.request.url.includes('/export') ||
      event.request.url.includes('/logout')) {
    return;
  }

  event.respondWith(
    caches.match(event.request)
      .then((cachedResponse) => {
        if (cachedResponse) {
          // Retourner la réponse du cache et mettre à jour en arrière-plan
          fetchAndUpdateCache(event.request);
          return cachedResponse;
        }

        // Si pas en cache, aller sur le réseau
        return fetch(event.request)
          .then((networkResponse) => {
            // Mettre en cache les réponses réussies
            if (networkResponse && networkResponse.status === 200 && 
                networkResponse.type === 'basic') {
              const responseToCache = networkResponse.clone();
              caches.open(DYNAMIC_CACHE_NAME)
                .then((cache) => {
                  cache.put(event.request, responseToCache);
                });
            }
            return networkResponse;
          })
          .catch(() => {
            // Page d'erreur hors ligne pour les pages HTML
            if (event.request.headers.get('accept').includes('text/html')) {
              return caches.match('/offline.html');
            }
            
            // Pour les autres ressources, retourner une réponse d'erreur
            return new Response('Hors ligne', {
              status: 503,
              statusText: 'Service Unavailable',
              headers: new Headers({
                'Content-Type': 'text/plain'
              })
            });
          });
      })
  );
});

// Fonction pour mettre à jour le cache en arrière-plan
function fetchAndUpdateCache(request) {
  fetch(request)
    .then((networkResponse) => {
      if (networkResponse && networkResponse.status === 200) {
        caches.open(DYNAMIC_CACHE_NAME)
          .then((cache) => {
            cache.put(request, networkResponse);
          });
      }
    })
    .catch(() => {
      // Échec silencieux en mode hors ligne
    });
}

// Gérer les messages depuis l'application
self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
  
  if (event.data && event.data.type === 'CLEAR_CACHE') {
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames.map((cacheName) => {
          return caches.delete(cacheName);
        })
      );
    }).then(() => {
      event.ports[0].postMessage({ success: true });
    });
  }
});

// Synchronisation en arrière-plan
self.addEventListener('sync', (event) => {
  if (event.tag === 'sync-cotisations') {
    event.waitUntil(syncCotisations());
  }
  
  if (event.tag === 'sync-data') {
    event.waitUntil(syncData());
  }
});

// Fonctions de synchronisation
async function syncCotisations() {
  console.log('[Service Worker] Synchronisation des cotisations...');
  // Ici, vous synchroniseriez les données en attente
}

async function syncData() {
  console.log('[Service Worker] Synchronisation générale des données...');
}