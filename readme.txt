=== Media SEO by Designs and IT ===
Contributors: designsandit
Tags: media, rename, alt text, image seo, ai
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Renombra imágenes sin romper enlaces y genera con IA el texto alternativo, el título y la leyenda de cada imagen.

== Description ==

Media SEO resuelve dos tareas de la biblioteca de medios que casi siempre se hacen a mano: ponerle un nombre útil al archivo y llenar sus textos.

**Renombrar sin romper nada**

* Mueve el archivo (no deja copias), regenera los tamaños y borra las miniaturas viejas.
* Actualiza los enlaces en páginas, entradas, productos de WooCommerce, Elementor, BeTheme, campos personalizados, categorías y widgets.
* Redirige con 301 la dirección vieja de la imagen y de cada miniatura a la nueva.
* Cada cambio se puede deshacer desde la ficha del archivo.

**Textos con IA**

* El botón "Generar con IA" propone un nombre de archivo y llena el texto alternativo, el título y la leyenda.
* Usa el nombre del archivo, la página donde se subió, el contexto del negocio (sector, ciudad, palabras clave) y, si lo activas, la propia imagen.
* Puede agregar la marca al final de cada título.
* Lleva el texto alternativo nuevo a las páginas ya publicadas.
* Procesamiento en lote para imágenes sin texto alternativo o con nombres como IMG_2034.jpg.

**Limpieza**

* Encuentra los archivos que no pertenecen a la biblioteca y que nada del sitio usa, y los mueve a una cuarentena reversible.

La IA es Claude, de Anthropic. Cada sitio usa su propia clave de API y paga solo lo que consume: aproximadamente USD 0,001 a 0,02 por imagen, según el modelo.

**Cuándo conviene renombrar**

Renombra las imágenes nuevas y las que tienen nombres genéricos. Si una imagen ya está indexada y su nombre es aceptable, cambiarle la URL puede hacerla bajar un tiempo en Google Imágenes, aun con la redirección. En esos casos usa el plugin solo para los textos.

== Installation ==

1. En Plugins > Añadir nuevo > Subir plugin, sube el archivo `media-seo-designsandit.zip` y actívalo.
2. En Ajustes > Media SEO pon la clave de la API de Anthropic. Más seguro: defínela en `wp-config.php` con `define( 'DITMS_ANTHROPIC_API_KEY', '...' );`.
3. Llena el contexto del negocio y, si quieres, la marca para los títulos.

Las versiones nuevas aparecen en Plugins como cualquier otra actualización.

== Frequently Asked Questions ==

= ¿Funciona sin IA? =

Sí. El renombrado, las redirecciones y el deshacer no usan la IA.

= ¿Qué pasa si la redirección no funciona? =

Abre la URL vieja de la imagen. Si da error 404, el servidor no está enviando a WordPress las peticiones de archivos que no existen. Pasa con algunas configuraciones de Nginx y se arregla en la configuración del servidor.

= ¿Por qué no llena la descripción por defecto? =

Porque la descripción solo aparece en la página de adjunto de la imagen, que WordPress desactiva desde la versión 6.4. Se puede activar en los ajustes.

== Changelog ==

= 1.5.0 =
* Nueva pantalla Medios > Archivos sin uso: encuentra los archivos de uploads que no pertenecen a ninguna imagen de la biblioteca (como las copias que dejaba Rename Media Files), comprueba que nada del sitio los use y los mueve a una cuarentena de donde se pueden restaurar.

= 1.4.0 =
* El texto alternativo nuevo llega también a las páginas ya publicadas con el editor de bloques o el clásico. Solo cambia el alt de la página si estaba vacío o era igual al anterior.
* Botón "Probar conexión" para comprobar la clave de la API sin gastar.
* Uso de la IA por mes en los ajustes: peticiones, tokens y costo estimado.
* Corregido: Deshacer vuelve exactamente al nombre original, con sus mayúsculas y guiones bajos.
* Corregido: los nombres de cámara ya limpiados al subir (como img-2034.jpg) se reconocen como genéricos en el lote.

= 1.3.0 =
* Actualizaciones automáticas desde GitHub.
* Enlaces a Designs and IT en los ajustes.

= 1.2.0 =
* El texto alternativo ya no se corta a mitad de frase.
* Marca configurable al final del título, con separador a elección.
* La descripción deja de llenarse por defecto.

= 1.1.2 =
* Corregido: el nombre propuesto por la IA podía quedar con "-jpg" antes de la extensión.

= 1.1.1 =
* Redirección de respaldo con el historial de renombres, también para las miniaturas.
* Lista de redirecciones guardadas en los ajustes.
* La ventana de medios muestra el nombre y la URL nuevos apenas se renombra.

= 1.1.0 =
* Compatibilidad con Elementor, WooCommerce y BeTheme (incluido el constructor guardado en base64 y sus archivos CSS).
* Botón para deshacer renombres y textos.
* Interfaz nueva, sin jQuery.

= 1.0.0 =
* Primera versión.
