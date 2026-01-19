<?php
// generate-icons.php
// Ce script génère les icônes PWA à partir d'une image source

$sourceImage = 'source-icon.png'; // Mettez votre icône source ici
$sizes = [72, 96, 128, 144, 152, 192, 384, 512];

if (!file_exists($sourceImage)) {
    echo "Image source non trouvée!";
    exit;
}

// Créer le dossier icons s'il n'existe pas
if (!is_dir('icons')) {
    mkdir('icons', 0755, true);
}

foreach ($sizes as $size) {
    $image = imagecreatefrompng($sourceImage);
    $resized = imagescale($image, $size, $size);
    
    $filename = "icons/icon-{$size}x{$size}.png";
    imagepng($resized, $filename);
    
    imagedestroy($image);
    imagedestroy($resized);
    
    echo "Icône {$size}x{$size} générée<br>";
}

// Générer aussi favicon.ico
$image = imagecreatefrompng($sourceImage);
$resized16 = imagescale($image, 16, 16);
$resized32 = imagescale($image, 32, 32);

// Créer un ICO
$ico = fopen('favicon.ico', 'wb');
// En-tête ICO
fwrite($ico, pack('v', 0)); // Réservé
fwrite($ico, pack('v', 1)); // Type (1 = ICO)
fwrite($ico, pack('v', 2)); // Nombre d'images

// Écrire les entrées pour chaque taille
writeIcoEntry($ico, $resized16, 16);
writeIcoEntry($ico, $resized32, 32);

// Écrire les données
writeIcoImageData($ico, $resized16);
writeIcoImageData($ico, $resized32);

fclose($ico);

echo "Favicon.ico généré<br>";
echo "Toutes les icônes ont été générées avec succès!";

function writeIcoEntry($handle, $image, $size) {
    $width = imagesx($image);
    $height = imagesy($image);
    
    fwrite($handle, pack('C', $width)); // Largeur
    fwrite($handle, pack('C', $height)); // Hauteur
    fwrite($handle, pack('C', 0)); // Nombre de couleurs
    fwrite($handle, pack('C', 0)); // Réservé
    fwrite($handle, pack('v', 1)); // Plans de couleur
    fwrite($handle, pack('v', 32)); // Bits par pixel
    fwrite($handle, pack('V', 40 + ($width * $height * 4))); // Taille des données
    fwrite($handle, pack('V', 22)); // Offset des données
}

function writeIcoImageData($handle, $image) {
    $width = imagesx($image);
    $height = imagesy($image);
    
    // En-tête BITMAPINFOHEADER
    fwrite($handle, pack('V', 40)); // Taille de l'en-tête
    fwrite($handle, pack('V', $width)); // Largeur
    fwrite($handle, pack('V', $height * 2)); // Hauteur
    fwrite($handle, pack('v', 1)); // Plans
    fwrite($handle, pack('v', 32)); // Bits par pixel
    fwrite($handle, pack('V', 0)); // Compression
    fwrite($handle, pack('V', $width * $height * 4)); // Taille image
    fwrite($handle, pack('V', 0)); // Résolution horizontale
    fwrite($handle, pack('V', 0)); // Résolution verticale
    fwrite($handle, pack('V', 0)); // Couleurs utilisées
    fwrite($handle, pack('V', 0)); // Couleurs importantes
    
    // Données des pixels
    for ($y = $height - 1; $y >= 0; $y--) {
        for ($x = 0; $x < $width; $x++) {
            $color = imagecolorat($image, $x, $y);
            $a = ($color >> 24) & 0xFF;
            $r = ($color >> 16) & 0xFF;
            $g = ($color >> 8) & 0xFF;
            $b = $color & 0xFF;
            
            fwrite($handle, pack('C', $b));
            fwrite($handle, pack('C', $g));
            fwrite($handle, pack('C', $r));
            fwrite($handle, pack('C', $a));
        }
    }
}
?>