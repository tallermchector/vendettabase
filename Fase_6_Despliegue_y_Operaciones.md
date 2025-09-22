# Fase 6: Despliegue y Operaciones

Este documento final describe el plan para el despliegue, la infraestructura y el mantenimiento a largo plazo de la aplicación "Vendetta-Legacy".

## 1. Infraestructura y Despliegue

La elección de la infraestructura adecuada es clave para la escalabilidad, el rendimiento y una buena experiencia de desarrollo.

### 1.1. Hosting de la Aplicación

**Recomendación:** **Vercel**.
Vercel es la plataforma de despliegue creada por el mismo equipo que desarrolla Next.js. La sinergia es total.

**Ventajas Clave:**
-   **Integración Nativa:** Despliegue optimizado para aplicaciones Next.js, soportando todas sus características (API Routes, Server Components, Server Actions, etc.) sin configuración adicional.
-   **Despliegues Git-Based:** Conectar el repositorio de GitHub a Vercel permite despliegues automáticos en cada `merge` a la rama `main`.
-   **Previews de Despliegue:** Por cada Pull Request, Vercel genera una URL de vista previa con una instancia completamente funcional de la aplicación, facilitando la revisión de cambios antes de fusionarlos.
-   **Vercel Cron Jobs:** Integración nativa para ejecutar las tareas programadas que definimos en la Fase 3, sin necesidad de servicios externos.
-   **CDN Global:** El contenido estático se distribuye automáticamente a través de una red de entrega de contenidos global, garantizando tiempos de carga rápidos para usuarios de todo el mundo.

### 1.2. Base de Datos

**Recomendación:** Un proveedor de bases de datos PostgreSQL gestionado en la nube.
La base de datos no se alojará en Vercel. Vercel se conectará a ella a través de la `DATABASE_URL`.

**Opciones Populares:**
-   **Neon:** Una opción moderna de PostgreSQL "serverless" que escala a cero cuando no está en uso, ideal para desarrollo y aplicaciones con tráfico variable.
-   **Supabase:** Ofrece una base de datos PostgreSQL junto con otras herramientas de backend, aunque en nuestro caso solo necesitaríamos la base de datos.
-   **AWS RDS (Relational Database Service):** Una solución robusta y escalable de Amazon Web Services, ideal para aplicaciones de gran escala.
-   **Google Cloud SQL** o **Azure Database for PostgreSQL**.

La elección final dependerá del presupuesto y los requisitos de escalabilidad. Para empezar, Neon es una excelente opción.

### 1.3. Proceso de Despliegue

1.  **Crear un Proyecto en Vercel:** Iniciar sesión en Vercel con la cuenta de GitHub y seleccionar el repositorio del juego.
2.  **Configurar Variables de Entorno:** En el panel de control del proyecto en Vercel, añadir las variables de entorno necesarias para producción:
    -   `DATABASE_URL`: La cadena de conexión de la base de datos de producción.
    -   `NEXTAUTH_SECRET`: Un secreto largo y aleatorio para firmar los JWT de Next-Auth.
    -   `CRON_SECRET`: El secreto para proteger los endpoints de las tareas programadas.
3.  **Desplegar:** Vercel detectará que es un proyecto Next.js y lo desplegará automáticamente.

A partir de este punto, cada `git push` a la rama `main` activará un nuevo despliegue a producción.

## 2. Gestión de Entornos

Es fundamental separar los entornos para un ciclo de desarrollo seguro y predecible. Necesitaremos al menos tres bases de datos distintas, una para cada entorno.

1.  **Desarrollo (Local):**
    -   **Aplicación:** Se ejecuta en la máquina local de cada desarrollador (`npm run dev`).
    -   **Base de Datos:** Una instancia de PostgreSQL corriendo localmente (ej. vía Docker) o una base de datos gratuita de un proveedor como Neon. La `DATABASE_URL` se configura en el archivo `.env.local`.

2.  **Staging (Pre-producción):**
    -   **Aplicación:** Las "Preview Deployments" de Vercel que se generan para cada Pull Request.
    -   **Base de Datos:** Una base de datos separada en el proveedor cloud, dedicada a pruebas. La `DATABASE_URL` se configura en las variables de entorno de Vercel para el entorno de "Preview". Esto permite probar los cambios con una base de datos similar a la de producción pero sin afectar los datos reales.

3.  **Producción:**
    -   **Aplicación:** La versión principal que se despliega desde la rama `main` en Vercel.
    -   **Base de Datos:** La base de datos de producción principal. La `DATABASE_URL` se configura en las variables de entorno de Vercel para el entorno de "Production".

## 3. Monitorización y Alertas

Una vez en producción, es vital monitorizar la salud y el rendimiento de la aplicación.

### 3.1. Frontend y Edge

-   **Vercel Analytics:** Proporciona métricas de rendimiento web (Core Web Vitals) y de tráfico de visitantes, listas para usar sin configuración adicional.
-   **Sentry:** (Recomendado) Una herramienta excelente para la captura y gestión de errores tanto en el frontend como en el backend (API Routes). Se integra fácilmente con Next.js y nos alertará en tiempo real si los usuarios experimentan fallos.

### 3.2. Backend (Base de Datos)

-   **Monitorización del Proveedor:** La mayoría de los proveedores de bases de datos en la nube (AWS RDS, Neon, etc.) ofrecen paneles de control para monitorizar el uso de CPU, la memoria, el número de conexiones y el rendimiento de las consultas.
-   **Alertas de Cuellos de Botella:** Es crucial configurar alertas. Por ejemplo, recibir una notificación si el uso de la CPU de la base de datos supera el 80% durante más de 5 minutos. Esto nos permite reaccionar a posibles problemas de rendimiento antes de que afecten a todos los usuarios.
-   **Prisma Data Platform:** (Opcional) Prisma ofrece una plataforma de datos que proporciona información detallada sobre el rendimiento de las consultas, ayudando a identificar y optimizar las consultas lentas.
