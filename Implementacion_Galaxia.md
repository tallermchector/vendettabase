# Implementación Detallada: Mapa de la Galaxia (Revisado)

Este documento proporciona una guía técnica completa y autocontenida para desarrollar la página del **Mapa de la Galaxia**, que corresponde a la antigua `mapa.php`.

---

### **1. Ruta del Archivo**

`src/app/galaxy/page.tsx`

---

### **2. Objetivo de la Página**

Crear un mapa interactivo para que el jugador visualice sistemas solares, vea otros planetas, y lance misiones (ataque, espionaje, transporte) navegando por coordenadas.

---

### **3. Tablas y Campos de Base de Datos Utilizados**

-   **`Building`**:
    -   `id`, `coordX`, `coordY`, `coordZ`: Para localizar y mostrar planetas en el mapa.
    -   `userId`: Para enlazar con el propietario.
-   **`User`** (relacionado con `Building`):
    -   `username`, `isBanned`: Para mostrar el estado del propietario del planeta.
-   **`Troop`** (relacionado con `Building`):
    -   Todos los campos de unidades: Para verificar si el jugador tiene suficientes tropas para una misión.
-   **`Mission`**:
    -   Todos los campos: Para crear un nuevo registro de misión cuando se lanza una flota.

---

### **4. Lógica de Obtención de Datos (Queries)**

La página lee las coordenadas de la URL (`searchParams`) para mostrar la vista correcta del mapa.

**`src/lib/features/galaxy/queries.ts`**:

```typescript
import { prisma } from "@/lib/core/prisma";
import { cache } from 'react';

export const getGalaxyViewData = cache(async (coordX: number, coordY: number) => {
  const viewBoxSize = 10;
  const planetsInView = await prisma.building.findMany({
    where: {
      coordX: { gte: coordX, lte: coordX + viewBoxSize },
      coordY: { gte: coordY, lte: coordY + viewBoxSize },
    },
    select: {
      id: true,
      coordX: true,
      coordY: true,
      coordZ: true,
      user: { select: { username: true, isBanned: true } },
    },
  });
  return planetsInView;
});

export const getUserPlanets = cache(async (userId: string) => {
  const userPlanets = await prisma.building.findMany({
    where: { userId },
    select: { id: true, coordX: true, coordY: true, coordZ: true, troops: true },
  });
  return userPlanets;
});
```

**`src/app/galaxy/page.tsx`**:

```tsx
// ... (imports de session, auth, redirect)
import { getGalaxyViewData, getUserPlanets } from "@/lib/features/galaxy/queries";
import { GalaxyView } from "@/components/features/galaxy/GalaxyView";

export default async function GalaxyPage({ searchParams }) {
  const session = await getServerSession(authOptions);
  if (!session?.user?.id) redirect("/login");

  const coordX = parseInt(searchParams.coordX as string) || 1;
  const coordY = parseInt(searchParams.coordY as string) || 1;

  const [planetsInView, userPlanets] = await Promise.all([
    getGalaxyViewData(coordX, coordY),
    getUserPlanets(session.user.id),
  ]);

  return (
    <div className="container mx-auto p-4">
      <h1 className="text-3xl font-bold mb-4">Galaxia</h1>
      {/* El componente de navegación se puede integrar en GalaxyView */}
      <GalaxyView
        initialPlanets={planetsInView}
        userPlanets={userPlanets}
        currentCoords={{ coordX, coordY }}
      />
    </div>
  );
}
```

---

### **5. Desglose de Componentes**

#### **`GalaxyView` (Client Component)**
-   **Ruta:** `src/components/features/galaxy/GalaxyView.tsx`
-   **Propósito:** Contenedor interactivo principal del mapa.
-   **Lógica:**
    -   `"use client"`.
    -   Maneja el estado del `selectedPlanet` y la apertura del `MissionLaunchModal`.
    -   Contiene la lógica de navegación (inputs de coordenadas y botón "Ir") que utiliza `useRouter` para cambiar los `searchParams` de la URL.
    -   Renderiza la cuadrícula de planetas y los componentes `PlanetDetailPanel` y `MissionLaunchModal` condicionalmente.

#### **`PlanetDetailPanel` (Client Component)**
-   **Ruta:** `src/components/features/galaxy/PlanetDetailPanel.tsx`
-   **Propósito:** Muestra los detalles del planeta seleccionado y los botones de acción.
-   **Lógica:** Muestra datos del `selectedPlanet`. Los botones "Atacar", "Espiar", etc., modifican el estado en `GalaxyView` para abrir el modal de misión con el tipo correcto.

#### **`MissionLaunchModal` (Client Component)**
-   **Ruta:** `src/components/features/galaxy/MissionLaunchModal.tsx`
-   **Propósito:** Formulario para configurar y lanzar una misión.
-   **Lógica:** Formulario complejo con `useState` para manejar la selección de planeta de origen, tropas y recursos. El botón "Lanzar" invoca la `launchMissionAction` con `useTransition`.

---

### **6. Lógica de Mutación de Datos (Server Actions)**

La acción de lanzar una misión es el núcleo de esta funcionalidad.

**`src/lib/features/missions/actions.ts`**:

```typescript
"use server";
import { prisma } from "@/lib/core/prisma";
import { authOptions } from "@/lib/core/auth";
import { getServerSession } from "next-auth/next";
import { revalidatePath } from "next/cache";
import { z } from "zod";
// import { calculateMissionDuration } from '@/lib/gameRules';

// Esquema de validación con Zod
const launchMissionSchema = z.object({
  originPlanetId: z.string().cuid(),
  targetPlanetId: z.string().cuid(),
  missionType: z.enum(["ATTACK", "TRANSPORT", "SPY"]),
  // ... otros campos
});

export async function launchMissionAction(payload: unknown) {
  const session = await getServerSession(authOptions);
  if (!session?.user?.id) return { error: "No autenticado" };

  const validation = launchMissionSchema.safeParse(payload);
  if (!validation.success) return { error: "Datos de misión inválidos." };

  const { originPlanetId, missionType, troops } = validation.data;

  try {
    await prisma.$transaction(async (tx) => {
      // 1. Obtener planeta de origen y validar propiedad y tropas
      const originPlanet = await tx.building.findFirst({
        where: { id: originPlanetId, userId: session.user.id },
        include: { troops: true },
      });

      // if (!originPlanet || !hasEnoughTroops(originPlanet.troops, troops)) {
      //   throw new Error("Tropas insuficientes o planeta de origen inválido.");
      // }

      // 2. Restar tropas
      // await tx.troop.update(...);

      // 3. Crear la misión
      // const duration = calculateMissionDuration(...);
      // await tx.mission.create({ data: { ... } });
    });
  } catch (error) {
    return { error: error.message };
  }

  // Revalidar el dashboard para mostrar la flota en movimiento
  revalidatePath('/dashboard');

  return { success: true };
}
```
Este diseño separa la compleja UI del cliente de la lógica de negocio segura y atómica del servidor.
