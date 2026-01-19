// register-sw.js
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function() {
    navigator.serviceWorker.register('/sw.js')
      .then(function(registration) {
        console.log('Service Worker enregistré avec succès:', registration.scope);
        
        // Vérifier les mises à jour
        registration.addEventListener('updatefound', () => {
          const newWorker = registration.installing;
          console.log('Nouveau Service Worker trouvé:', newWorker);
          
          newWorker.addEventListener('statechange', () => {
            if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
              showUpdateNotification();
            }
          });
        });
      })
      .catch(function(error) {
        console.log('Échec de l\'enregistrement du Service Worker:', error);
      });
    
    // Gérer les messages du Service Worker
    navigator.serviceWorker.addEventListener('message', event => {
      if (event.data && event.data.type === 'CACHE_UPDATED') {
        showCacheUpdatedNotification();
      }
    });
    
    // Demander les permissions
    requestNotificationPermission();
    requestInstallPrompt();
  });
}

// Fonction pour demander la permission des notifications
function requestNotificationPermission() {
  if ('Notification' in window && Notification.permission === 'default') {
    Notification.requestPermission().then(permission => {
      if (permission === 'granted') {
        console.log('Permission de notification accordée');
      }
    });
  }
}

// Gérer l'installation de l'app
let deferredPrompt;
function requestInstallPrompt() {
  window.addEventListener('beforeinstallprompt', (e) => {
    // Empêcher le prompt automatique
    e.preventDefault();
    deferredPrompt = e;
    
    // Afficher un bouton d'installation
    showInstallButton();
  });
}

// Afficher le bouton d'installation
function showInstallButton() {
  const installBtn = document.createElement('button');
  installBtn.id = 'installPWA';
  installBtn.innerHTML = `
    <i class="fas fa-download"></i>
    Installer l'application
  `;
  installBtn.style.cssText = `
    position: fixed;
    bottom: 20px;
    right: 20px;
    padding: 12px 20px;
    background: linear-gradient(135deg, #d4af37, #e6c34d);
    color: #0f1a3a;
    border: none;
    border-radius: 50px;
    font-weight: bold;
    cursor: pointer;
    z-index: 9999;
    box-shadow: 0 5px 20px rgba(0,0,0,0.2);
    display: flex;
    align-items: center;
    gap: 10px;
  `;
  
  installBtn.addEventListener('click', () => {
    if (deferredPrompt) {
      deferredPrompt.prompt();
      deferredPrompt.userChoice.then((choiceResult) => {
        if (choiceResult.outcome === 'accepted') {
          console.log('Utilisateur a installé l\'app');
        }
        deferredPrompt = null;
        installBtn.remove();
      });
    }
  });
  
  document.body.appendChild(installBtn);
  
  // Cacher après 10 secondes
  setTimeout(() => {
    if (installBtn.parentNode) {
      installBtn.style.opacity = '0';
      installBtn.style.transition = 'opacity 0.5s';
      setTimeout(() => installBtn.remove(), 500);
    }
  }, 10000);
}

// Afficher une notification de mise à jour
function showUpdateNotification() {
  if ('Notification' in window && Notification.permission === 'granted') {
    const notification = new Notification('Mise à jour disponible', {
      body: 'Une nouvelle version de l\'application est disponible. Cliquez pour rafraîchir.',
      icon: '/icons/icon-192x192.png',
      tag: 'update-available'
    });
    
    notification.onclick = () => {
      window.location.reload();
      notification.close();
    };
  } else {
    // Notification HTML si les notifications système ne sont pas autorisées
    const updateDiv = document.createElement('div');
    updateDiv.id = 'updateNotification';
    updateDiv.innerHTML = `
      <div style="padding: 10px; background: #4CAF50; color: white; text-align: center;">
        Nouvelle version disponible ! 
        <button onclick="window.location.reload()" style="margin-left: 10px; padding: 5px 10px; background: white; color: #4CAF50; border: none; border-radius: 3px; cursor: pointer;">
          Rafraîchir
        </button>
      </div>
    `;
    document.body.prepend(updateDiv);
  }
}

// Afficher une notification de cache mis à jour
function showCacheUpdatedNotification() {
  console.log('Cache mis à jour');
  // Vous pouvez ajouter une notification visuelle ici
}

// Gérer la synchronisation en arrière-plan
function syncBackgroundData() {
  if ('serviceWorker' in navigator && 'SyncManager' in window) {
    navigator.serviceWorker.ready.then(registration => {
      return registration.sync.register('sync-data');
    }).then(() => {
      console.log('Synchronisation en arrière-plan programmée');
    }).catch(err => {
      console.log('Échec de la synchronisation:', err);
    });
  }
}

// Détecter le mode d'affichage
function detectDisplayMode() {
  let displayMode = 'browser';
  if (window.matchMedia('(display-mode: standalone)').matches) {
    displayMode = 'standalone';
  } else if (window.navigator.standalone) {
    displayMode = 'standalone';
  } else if (document.referrer.includes('android-app://')) {
    displayMode = 'standalone';
  }
  
  // Ajouter une classe au body pour le mode
  document.body.classList.add('pwa-mode-' + displayMode);
  
  return displayMode;
}

// Initialiser
document.addEventListener('DOMContentLoaded', () => {
  detectDisplayMode();
  
  // Ajouter un événement pour la synchronisation manuelle
  if ('serviceWorker' in navigator && 'SyncManager' in window) {
    const syncBtn = document.getElementById('syncBtn');
    if (syncBtn) {
      syncBtn.addEventListener('click', syncBackgroundData);
    }
  }
  
  // Gérer la déconnexion en PWA
  const logoutLinks = document.querySelectorAll('a[href*="logout"]');
  logoutLinks.forEach(link => {
    link.addEventListener('click', (e) => {
      if (window.matchMedia('(display-mode: standalone)').matches) {
        e.preventDefault();
        if (confirm('Voulez-vous vous déconnecter ?')) {
          // Nettoyer le cache avant la déconnexion
          if ('caches' in window) {
            caches.keys().then(cacheNames => {
              cacheNames.forEach(cacheName => {
                caches.delete(cacheName);
              });
            });
          }
          window.location.href = link.href;
        }
      }
    });
  });
});