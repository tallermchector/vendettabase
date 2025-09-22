# Fase 6: Despliegue y Operaciones (Revisado)

Este documento final describe el plan para el despliegue, la infraestructura y el mantenimiento a largo plazo de la aplicación "Vendetta-Legacy".

## 1. Infraestructura y Despliegue

La elección de la infraestructura adecuada es clave para la escalabilidad, el rendimiento y una buena experiencia de desarrollo.

### 1.1. Hosting de la Aplicación

**Recomendación:** **Vercel**.
Vercel es la plataforma de despliegue creada por el mismo equipo que desarrolla Next.js. La sinergia es total y está diseñada para manejar la estructura `src/` sin ninguna configuración adicional.

**Ventajas Clave:**
-   **Integración Nativa:** Despliegue optimizado para aplicaciones Next.js. Vercel detecta automáticamente el uso del App Router y el directorio `src/`, aplicando las optimizaciones de compilación correctas.
-   **Despliegues Git-Based:** Conectar el repositorio de GitHub a Vercel permite despliegues automáticos en cada `merge` a la rama `main`.
-   **Previews de Despliegue:** Por cada Pull Request, Vercel genera una URL de vista previa con una instancia completamente funcional de la aplicación, facilitando la revisión de cambios antes de fusionarlos.
-   **Vercel Cron Jobs:** Integración nativa para ejecutar las tareas programadas definidas en `vercel.json`, eliminando la necesidad de servicios externos.
-   **CDN Global:** El contenido estático se distribuye automáticamente a través de una red de entrega de contenidos global, garantizando tiempos de carga rápidos para usuarios de todo el mundo.

### 1.2. Base de Datos

**Recomendación:** Un proveedor de bases de datos PostgreSQL gestionado en la nube.
La base de datos es un servicio separado y Vercel se conectará a ella a través de la `DATABASE_URL` en las variables de entorno.

**Opciones Populares:**
-   **Neon:** Una opción moderna de PostgreSQL "serverless" que escala a cero cuando no está en uso, ideal para desarrollo y aplicaciones con tráfico variable.
-   **Supabase:** Ofrece una base de datos PostgreSQL junto con otras herramientas de backend.
-   **AWS RDS (Relational Database Service):** Una solución robusta y escalable de Amazon Web Services, ideal para aplicaciones de gran escala.

La elección final dependerá del presupuesto y los requisitos de escalabilidad. Neon es una excelente opción para empezar.

### 1.3. Proceso de Despliegue

1.  **Crear un Proyecto en Vercel:** Iniciar sesión en Vercel con la cuenta de GitHub y seleccionar el repositorio del juego.
2.  **Configurar el Proyecto:** Vercel detectará automáticamente que es un proyecto Next.js. No se necesita configuración adicional para que reconozca la estructura `src/`.
3.  **Configurar Variables de Entorno:** En el panel de control del proyecto en Vercel, añadir las variables de entorno necesarias para producción:
    -   `DATABASE_URL`: La cadena de conexión de la base de datos de producción.
    -   `NEXTAUTH_SECRET`: Un secreto largo y aleatorio para firmar los JWT de Next-Auth.
    -   `CRON_SECRET`: El secreto para proteger los endpoints de las tareas programadas.
4.  **Desplegar:** El primer despliegue se activará al crear el proyecto. A partir de ahí, cada `git push` a la rama `main` activará un nuevo despliegue a producción.

## 2. Gestión de Entornos

Es fundamental separar los entornos para un ciclo de desarrollo seguro. Necesitaremos al menos tres bases de datos distintas.

1.  **Desarrollo (Local):**
    -   **Aplicación:** Se ejecuta en la máquina local (`npm run dev`).
    -   **Base de Datos:** Una instancia de PostgreSQL corriendo localmente (vía Docker) o una base de datos gratuita de un proveedor como Neon. La `DATABASE_URL` se configura en `.env.local`.

2.  **Staging (Pre-producción):**
    -   **Aplicación:** Las "Preview Deployments" de Vercel generadas para cada Pull Request.
    -   **Base de Datos:** Una base de datos separada en el proveedor cloud, dedicada a pruebas. La `DATABASE_URL` se configura en las variables de entorno de Vercel para el entorno de "Preview".

3.  **Producción:**
    -   **Aplicación:** La versión principal que se despliega desde la rama `main` en Vercel.
    -   **Base de Datos:** La base de datos de producción principal. La `DATABASE_URL` se configura en las variables de entorno de Vercel para el entorno de "Production".

## 3. Monitorización y Alertas

Una vez en producción, es vital monitorizar la salud y el rendimiento de la aplicación.

-   **Frontend y Edge:**
    -   **Vercel Analytics:** Proporciona métricas de rendimiento web (Core Web Vitals) y de tráfico de visitantes, listas para usar.
    -   **Sentry:** (Recomendado) Herramienta para la captura de errores en el cliente y en el servidor (Server Actions, API Routes).
-   **Backend (Base de Datos):**
    -   **Monitorización del Proveedor:** La mayoría de los proveedores de bases de datos en la nube (AWS RDS, Neon, etc.) ofrecen paneles de control para monitorizar el uso de CPU, memoria y rendimiento de consultas.
    -   **Alertas de Cuellos de Botella:** Es crucial configurar alertas para reaccionar a problemas de rendimiento antes de que afecten a los usuarios (ej. uso de CPU > 80%).
    -   **Prisma Data Platform:** (Opcional) Proporciona información detallada sobre el rendimiento de las consultas de Prisma.
