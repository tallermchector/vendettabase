# Implementación: Dashboard (Visión General)

Este documento proporciona una guía técnica completa y autocontenida para desarrollar la página del **Dashboard**, que corresponde a la antigua `visiongeneral.php`. Esta es la página principal que los usuarios verán después de iniciar sesión.

---

### **1. Ruta del Archivo**

`app/dashboard/page.tsx`

---

### **2. Objetivo de la Página**

El Dashboard ofrece al jugador un resumen completo y de un solo vistazo de su imperio. Muestra información crítica como los planetas actuales, recursos, colas de construcción activas, movimientos de flotas y estadísticas generales. La página debe ser dinámica y reflejar las actualizaciones del juego en tiempo real o casi real.

---

### **3. Obtención de Datos (Data Fetching en el Servidor)**

La página `page.tsx` será un **Server Component** de React. Esto nos permite obtener todos los datos necesarios para la carga inicial directamente desde la base de datos en el servidor, antes de enviar la página al cliente. Esto resulta en una carga inicial muy rápida y eficiente.

**Lógica de `app/dashboard/page.tsx`:**

```tsx
import { getServerSession } from "next-auth/next";
import { authOptions } from "@/app/api/auth/[...nextauth]/route";
import { prisma } from "@/lib/prisma";
import { redirect } from "next/navigation";

// Importar los componentes que se describirán más adelante
import { PlanetInfo } from "@/components/dashboard/PlanetInfo";
import { ResourceBar } from "@/components/dashboard/ResourceBar";
import { ActivityQueues } from "@/components/dashboard/ActivityQueues";
import { FleetMovements } from "@/components/dashboard/FleetMovements";
import { PlayerStats } from "@/components/dashboard/PlayerStats";

export default async function DashboardPage() {
  // 1. Obtener la sesión del usuario
  const session = await getServerSession(authOptions);
  if (!session?.user?.id) {
    redirect("/login"); // Redirigir si no está autenticado
  }
  const userId = session.user.id;

  // 2. Obtener todos los datos del usuario en paralelo para máxima eficiencia
  const [
    user,
    mainBuilding, // Suponemos que el usuario tiene un edificio/planeta principal
    activeMissions,
  ] = await Promise.all([
    prisma.user.findUnique({
      where: { id: userId },
      select: {
        username: true,
        pointsTotal: true, // Asumiendo que hemos denormalizado los puntos para acceso rápido
        rankPosition: true,
      },
    }),
    prisma.building.findFirst({
      where: { userId: userId },
      include: {
        newConstructions: true, // Cola de construcción
        newTroops: true,        // Cola de tropas
        activeResearch: true,   // Cola de investigación
      },
    }),
    prisma.mission.findMany({
      where: {
        OR: [
          { userId: userId }, // Flotas propias
          // Aquí podría ir la lógica para flotas hostiles dirigiéndose al usuario
        ],
      },
      orderBy: { finishesAt: 'asc' },
    }),
  ]);

  if (!user || !mainBuilding) {
    // Manejar el caso en que el usuario no tenga datos iniciales
    return <div>Error: No se encontraron datos del jugador.</div>;
  }

  // 3. Pasar los datos a los componentes hijos
  return (
    <div className="container mx-auto p-4 space-y-6">
      <h1 className="text-3xl font-bold">Visión General de {user.username}</h1>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div className="lg:col-span-2 space-y-6">
          <PlanetInfo building={mainBuilding} />
          <ResourceBar initialResources={mainBuilding} />
          <ActivityQueues
            constructions={mainBuilding.newConstructions}
            troops={mainBuilding.newTroops}
            research={mainBuilding.activeResearch}
          />
        </div>
        <div className="space-y-6">
          <PlayerStats stats={user} />
          <FleetMovements missions={activeMissions} />
        </div>
      </div>
    </div>
  );
}
```

---

### **4. Desglose de Componentes**

#### **4.1. `PlanetInfo` (Server Component)**
-   **Ruta:** `components/dashboard/PlanetInfo.tsx`
-   **Propósito:** Muestra información estática del planeta principal del usuario.
-   **Props:** `{ building: Building }`
-   **Lógica:** Componente simple que recibe los datos del edificio y muestra su nombre, coordenadas y una imagen. Al ser un Server Component, no tiene estado ni interactividad.

#### **4.2. `ResourceBar` (Client Component)**
-   **Ruta:** `components/dashboard/ResourceBar.tsx`
-   **Propósito:** Muestra los recursos actuales del jugador (armamento, munición, etc.) y los actualiza en tiempo real según la producción por segundo.
-   **Props:** `{ initialResources: { armament: number, munition: number, ... } }`
-   **Lógica:**
    -   Debe ser un **Client Component** (`"use client"`).
    -   Utiliza el hook `useState` para inicializar el estado de los recursos con las `initialResources` recibidas del servidor.
    -   Utiliza `useEffect` para iniciar un `setInterval` que se ejecuta cada segundo.
    -   Dentro del intervalo, calcula la producción por segundo (este valor puede venir de una función de utilidad o de las props) y actualiza el estado de los recursos, provocando que la UI se vuelva a renderizar con los nuevos valores.
    -   El `useEffect` debe retornar una función de limpieza que elimine el `setInterval` cuando el componente se desmonte para evitar fugas de memoria.

```tsx
"use client";
import { useState, useEffect } from 'react';

// Supongamos que la producción por hora se pasa como prop o se calcula
const PRODUCTION_RATES = { armament: 100, munition: 50 }; // Por hora

export function ResourceBar({ initialResources }) {
  const [resources, setResources] = useState(initialResources);

  useEffect(() => {
    const interval = setInterval(() => {
      setResources(prev => ({
        ...prev,
        armament: prev.armament + PRODUCTION_RATES.armament / 3600,
        munition: prev.munition + PRODUCTION_RATES.munition / 3600,
      }));
    }, 1000);

    return () => clearInterval(interval);
  }, []);

  return (
    <div>
      <span>Armamento: {Math.floor(resources.armament)}</span>
      <span>Munición: {Math.floor(resources.munition)}</span>
      {/* ... otros recursos ... */}
    </div>
  );
}
```

#### **4.3. `ActivityQueues` (Client Component)**
-   **Ruta:** `components/dashboard/ActivityQueues.tsx`
-   **Propósito:** Muestra todas las colas activas (construcción, tropas, investigación) en un solo lugar.
-   **Props:** `{ constructions: NewConstruction[], troops: NewTroop[], research: ActiveResearch[] }`
-   **Lógica:**
    -   Es un **Client Component** para poder manejar los temporizadores.
    -   Recibe las listas de colas como props.
    -   Renderiza cada lista en una sección separada.
    -   Cada elemento de la lista (ej. `ConstructionQueueItem`) tendrá su propio temporizador de cuenta regresiva.
    -   **Temporizador:** Un componente hijo (`<CountdownTimer />`) recibirá la `finishesAt` (fecha de finalización) y usará `useState` y `setInterval` para calcular y mostrar el tiempo restante. Cuando el tiempo llega a cero, podría mostrar "Completado" y opcionalmente invocar una acción para refrescar los datos.

#### **4.4. `FleetMovements` (Client Component)**
-   **Ruta:** `components/dashboard/FleetMovements.tsx`
-   **Propósito:** Muestra las flotas en movimiento, tanto aliadas como enemigas.
-   **Props:** `{ missions: Mission[] }`
-   **Lógica:** Similar a `ActivityQueues`, es un **Client Component** que mapea sobre la lista de misiones y renderiza un componente `MissionItem` para cada una, el cual contendrá un `CountdownTimer` para mostrar el tiempo de llegada.

#### **4.5. `PlayerStats` (Server Component)**
-   **Ruta:** `components/dashboard/PlayerStats.tsx`
-   **Propósito:** Muestra estadísticas clave del jugador.
-   **Props:** `{ stats: { pointsTotal: number, rankPosition: number } }`
-   **Lógica:** Componente simple de solo lectura que muestra los puntos y la posición en el ranking.

---

### **5. Lógica del Lado del Cliente y Gestión de Estado**

-   **Estado Local:** El estado local (`useState`) es suficiente para los temporizadores de cuenta regresiva dentro de los componentes de las colas.
-   **Actualización de Recursos:** La barra de recursos maneja su propia lógica de "tick" de actualización. No se necesita una librería de estado global como Zustand para esta página si la lógica de recursos está contenida en `ResourceBar`.

---

### **6. Server Actions Relevantes**

Aunque la página es principalmente de visualización, las colas de actividad deberían tener un botón para **cancelar**. Esta acción es un candidato perfecto para una **Server Action**.

**Acción: `cancelConstructionAction`**
-   **Ruta:** `app/actions/queueActions.ts`
-   **Parámetros:** `(queueId: string)`
-   **Lógica:**
    1.  Marcar la función con `"use server"`.
    2.  Obtener la sesión del servidor para verificar que el usuario está autenticado.
    3.  Usar `prisma.newConstruction.delete()` para eliminar el elemento de la cola. Es crucial añadir una cláusula `where` para asegurar que el `queueId` pertenece al `userId` de la sesión, evitando que un usuario pueda cancelar la cola de otro.
        ```prisma
        await prisma.newConstruction.delete({
          where: { id: queueId, userId: session.user.id }
        });
        ```
    4.  Devolver los recursos al usuario si la cancelación lo requiere (dentro de una transacción de Prisma).
    5.  Llamar a `revalidatePath('/dashboard')`. Esto le indica a Next.js que debe volver a obtener los datos de la página del dashboard y enviarlos actualizados al cliente, eliminando el elemento cancelado de la UI automáticamente.

Este enfoque mantiene la lógica de mutación en el servidor y simplifica enormemente el código del cliente.
