# Media SEO by Designs and IT

Plugin de WordPress para renombrar imágenes sin romper enlaces y llenar con IA su texto alternativo, título y leyenda.

Lo hicimos en [Designs and IT](https://www.designsandit.com/?utm_source=github&utm_medium=referral&utm_campaign=media-seo) porque los plugins de renombrado que usábamos dejaban copias del archivo viejo, no actualizaban los enlaces dentro de Elementor ni de otros constructores, y no redirigían la URL anterior. Esto es lo que hace distinto:

- **Mueve el archivo, no lo copia.** Regenera los tamaños y borra las miniaturas viejas.
- **Actualiza los enlaces en todo el sitio:** páginas, entradas, productos de WooCommerce, Elementor, BeTheme (también cuando guarda el constructor en base64), campos personalizados, categorías y widgets.
- **Redirige con 301** la URL vieja de la imagen y de cada miniatura.
- **Deshace** cualquier renombre o cambio de textos desde la ficha del archivo.
- **Textos con IA (Claude, de Anthropic):** usa el nombre del archivo, la página donde está la imagen, el contexto del negocio y, si lo activas, la imagen misma. Puede agregar tu marca al final de cada título.
- **En lote:** imágenes sin texto alternativo o con nombres como `IMG_2034.jpg`.

## Requisitos

- WordPress 6.0 o superior y PHP 7.4 o superior.
- Una clave de API de Anthropic, solo para las funciones de IA. Cada sitio paga lo que consume: aproximadamente USD 0,001 a 0,02 por imagen, según el modelo.

## Instalación

1. Descarga `media-seo-designsandit.zip` del [último release](https://github.com/designsandit/media-seo-designsandit/releases/latest). No uses el botón "Code > Download ZIP": esa carpeta tiene otro nombre y WordPress no la reconoce para actualizarla.
2. En WordPress: Plugins > Añadir nuevo > Subir plugin.
3. En Ajustes > Media SEO pon la clave de la API. Lo más seguro es definirla en `wp-config.php`:

   ```php
   define( 'DITMS_ANTHROPIC_API_KEY', 'tu-clave' );
   ```

Las versiones nuevas aparecen en Plugins como cualquier otra actualización.

## Una recomendación antes de renombrar

Renombra las imágenes nuevas y las que tienen nombres genéricos. Si una imagen ya está indexada y su nombre es aceptable, cambiarle la URL puede hacerla bajar un tiempo en Google Imágenes, aun con la redirección. En esos casos usa el plugin solo para los textos.

Antes de renombrar en lote en un sitio en producción, haz una copia de seguridad de la base de datos.

## Límites conocidos

- La redirección necesita que el servidor envíe a WordPress las peticiones de archivos que no existen. Con Apache y el `.htaccess` de WordPress funciona. Con Nginx depende de la configuración.
- No renombra archivos que estén solo en un CDN o en S3.

## Licencia

GPL-2.0 o posterior. Incluye [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) (MIT) para las actualizaciones.

## Sobre Designs and IT

Agencia SEO boutique desde 2004, con clientes en Colombia y Estados Unidos. Medimos conversiones reales: WhatsApp, formularios y llamadas.

[www.designsandit.com](https://www.designsandit.com/?utm_source=github&utm_medium=referral&utm_campaign=media-seo) · info@designsandit.com
