# Implementación Detallada: Dashboard (Visión General) (Revisado)

Este documento proporciona una guía técnica completa y autocontenida para desarrollar la página del **Dashboard**, que corresponde a la antigua `visiongeneral.php`. Esta es la página principal que los usuarios verán después de iniciar sesión.

---

### **1. Ruta del Archivo**

`src/app/dashboard/page.tsx`

---

### **2. Objetivo de la Página**

El Dashboard ofrece al jugador un resumen completo y de un solo vistazo de su imperio. Muestra información crítica como los planetas actuales, recursos, colas de construcción activas, movimientos de flotas y estadísticas generales. La página debe sentirse dinámica, con temporizadores y contadores que se actualizan en el cliente.

---

### **3. Tablas y Campos de Base de Datos Utilizados**

Para renderizar esta página, la consulta principal necesitará acceder a los siguientes modelos y campos de Prisma:

-   **`User`**:
    -   `id`: Para identificar al usuario.
    -   `username`: Para mostrar en el encabezado.
    -   `pointsTotal`, `rankPosition`: Para el componente de estadísticas.
-   **`Building`** (asumiendo uno por usuario para simplificar):
    -   `id`: Para identificar el planeta/edificio.
    -   `coordX`, `coordY`, `coordZ`: Para mostrar la ubicación.
    -   `armament`, `munition`, `alcohol`, `dollars`: Para la barra de recursos.
-   **`NewConstruction`** (relacionado con `Building`):
    -   `id`, `room`, `level`, `finishesAt`: Para la cola de construcción.
-   **`NewTroop`** (relacionado con `Building`):
    -   `id`, `troopName`, `quantity`, `finishesAt`: Para la cola de entrenamiento de tropas.
-   **`ActiveResearch`** (relacionado con `User`):
    -   `id`, `research`, `level`, `finishesAt`: Para la cola de investigación.
-   **`Mission`** (relacionado con `User`):
    -   `id`, `type`, `originX`, `destX`, `finishesAt`: Para la lista de movimientos de flotas.

---

### **4. Lógica de Obtención de Datos (Queries)**

La página (`page.tsx`) será un **Server Component**. No usará `prisma` directamente. En su lugar, llamará a una función de consulta tipada y específica.

**`src/lib/features/dashboard/queries.ts`**:

```typescript
import { prisma } from "@/lib/core/prisma";
import { cache } from 'react';

// cache() de React deduplica las peticiones a esta función durante una misma renderización.
export const getDashboardData = cache(async (userId: string) => {
  const userWithDashboardData = await prisma.user.findUnique({
    where: { id: userId },
    select: {
      username: true,
      pointsTotal: true,
      rankPosition: true,
      // Obtener el primer edificio como el "principal"
      buildings: {
        take: 1,
        select: {
          id: true,
          coordX: true,
          coordY: true,
          coordZ: true,
          armament: true,
          munition: true,
          alcohol: true,
          dollars: true,
          newConstructions: { select: { id: true, room: true, level: true, finishesAt: true } },
          newTroops: { select: { id: true, troopName: true, quantity: true, finishesAt: true } },
        },
      },
      activeResearch: { select: { id: true, research: true, level: true, finishesAt: true } },
      missionsInitiated: { select: { id: true, type: true, originX: true, destX: true, finishesAt: true }, where: { /* misiones activas */ } },
    }
  });

  return userWithDashboardData;
});
```

**`src/app/dashboard/page.tsx`**:

```tsx
import { getServerSession } from "next-auth/next";
import { authOptions } from "@/lib/core/auth";
import { getDashboardData } from "@/lib/features/dashboard/queries";
import { redirect } from "next/navigation";
import { DashboardView } from "@/components/features/dashboard/DashboardView";

export default async function DashboardPage() {
  const session = await getServerSession(authOptions);
  if (!session?.user?.id) redirect("/login");

  const data = await getDashboardData(session.user.id);
  if (!data || data.buildings.length === 0) {
    return <div>Bienvenido. Aún no tienes un planeta.</div>;
  }

  return <DashboardView initialData={data} />;
}
```

---

### **5. Desglose de Componentes**

#### **`DashboardView` (Client Component)**
-   **Ruta:** `src/components/features/dashboard/DashboardView.tsx`
-   **Propósito:** Es el componente principal del lado del cliente que recibe todos los datos iniciales y gestiona la interactividad.
-   **Props:** `{ initialData }`
-   **Lógica:**
    -   Marcado con `"use client"`.
    -   Recibe `initialData` y lo pasa a sus componentes hijos.
    -   Podría inicializar un store de Zustand aquí si fuera necesario para compartir estado entre los componentes hijos.

#### **`ResourceBar` (Client Component)**
-   **Ruta:** `src/components/features/dashboard/ResourceBar.tsx`
-   **Propósito:** Muestra los recursos y los actualiza visualmente.
-   **Props:** `{ initialResources, productionRates }`
-   **Lógica:**
    -   Usa `useState` para inicializar los recursos con `initialResources`.
    -   Usa `useEffect` con `setInterval` para añadir la producción por segundo a los recursos del estado. La limpieza del intervalo en el `return` del `useEffect` es crucial.

#### **`ActivityQueues` (Client Component)**
-   **Ruta:** `src/components/features/dashboard/ActivityQueues.tsx`
-   **Propósito:** Muestra las colas de construcción, tropas e investigación.
-   **Props:** `{ constructions, troops, research }`
-   **Lógica:** Contiene tres listas, cada una mapeando sus datos a un `QueueItem` que incluye un `CountdownTimer` para mostrar el tiempo restante.

#### **`CountdownTimer` (Client Component)**
-   **Ruta:** `src/components/ui/CountdownTimer.tsx`
-   **Propósito:** Componente reutilizable para mostrar una cuenta regresiva.
-   **Props:** `{ finishesAt: Date }`
-   **Lógica:** Calcula la diferencia entre `finishesAt` y la hora actual dentro de un `setInterval` en un `useEffect` y actualiza el estado local que muestra el tiempo restante (ej. "01:23:45").

---

### **6. Lógica de Mutación de Datos (Server Actions)**

Las acciones como "cancelar" una construcción se definen en su propio módulo.

**`src/lib/features/buildings/actions.ts`**:

```typescript
"use server";
import { prisma } from "@/lib/core/prisma";
import { authOptions } from "@/lib/core/auth";
import { getServerSession } from "next-auth/next";
import { revalidatePath } from "next/cache";

export async function cancelConstructionAction(queueId: string) {
  const session = await getServerSession(authOptions);
  if (!session?.user?.id) return { error: "No autenticado" };

  try {
    // La transacción asegura que la devolución de recursos y la cancelación ocurran juntas.
    await prisma.$transaction(async (tx) => {
      const constructionToCancel = await tx.newConstruction.findUnique({
        where: { id: queueId, userId: session.user.id }, // ¡Validación de propiedad!
      });

      if (!constructionToCancel) throw new Error("Construcción no encontrada o no pertenece al usuario.");

      // Lógica para calcular y devolver los recursos al usuario...
      // await tx.building.update(...);

      // Eliminar de la cola
      await tx.newConstruction.delete({ where: { id: queueId } });
    });
  } catch (error) {
    return { error: error.message };
  }

  // Revalidar las páginas afectadas para que la UI se actualice
  revalidatePath('/dashboard');
  revalidatePath('/buildings');

  return { success: true };
}
```
Un botón en el componente `ActivityQueues` podría invocar esta acción, usando `useTransition` para un feedback inmediato.
