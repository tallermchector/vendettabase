# Implementación: Mapa de la Galaxia

Este documento proporciona una guía técnica completa y autocontenida para desarrollar la página del **Mapa de la Galaxia**, que corresponde a la antigua `mapa.php`. Esta es la interfaz principal para la interacción del jugador con el universo del juego.

---

### **1. Ruta del Archivo**

`app/galaxy/page.tsx`

*(Nota: La navegación por el mapa se gestionará mediante parámetros de búsqueda en la URL, como `app/galaxy?system=123&galaxy=4`, que serán leídos por el Server Component para obtener los datos correspondientes a esa vista.)*

---

### **2. Objetivo de la Página**

El objetivo es crear un mapa interactivo donde el jugador pueda:
1.  Visualizar sistemas solares y los planetas que contienen.
2.  Ver información básica de los planetas (propietario, nombre, estado).
3.  Seleccionar un planeta para ver detalles y obtener opciones de interacción.
4.  Lanzar misiones (ataque, espionaje, transporte, etc.) a otros planetas.
5.  Navegar fácilmente a diferentes coordenadas de la galaxia.

---

### **3. Obtención de Datos y Lógica del Servidor**

La página `page.tsx` será un **Server Component** que lee las coordenadas de la URL para obtener los datos de esa sección del mapa.

**Lógica de `app/galaxy/page.tsx`:**

```tsx
import { getServerSession } from "next-auth/next";
import { authOptions } from "@/app/api/auth/[...nextauth]/route";
import { prisma } from "@/lib/prisma";
import { redirect } from "next/navigation";

// Importar componentes
import { GalaxyView } from "@/components/galaxy/GalaxyView";
import { GalaxyNavigation } from "@/components/galaxy/GalaxyNavigation";

// Tipos para los parámetros de búsqueda
interface GalaxyPageProps {
  searchParams: { [key: string]: string | string[] | undefined };
}

export default async function GalaxyPage({ searchParams }: GalaxyPageProps) {
  const session = await getServerSession(authOptions);
  if (!session?.user?.id) {
    redirect("/login");
  }

  // 1. Determinar las coordenadas a mostrar desde la URL, con valores por defecto
  const coordX = parseInt(searchParams.coordX as string) || 1;
  const coordY = parseInt(searchParams.coordY as string) || 1;

  // 2. Obtener los planetas en esa vista del mapa.
  // Se busca en un rango alrededor de las coordenadas centrales.
  const viewBoxSize = 10;
  const planetsInView = await prisma.building.findMany({
    where: {
      coordX: { gte: coordX, lte: coordX + viewBoxSize },
      coordY: { gte: coordY, lte: coordY + viewBoxSize },
    },
    include: {
      user: { // Incluir datos del propietario del planeta
        select: { username: true, isBanned: true },
      },
    },
  });

  // 3. Obtener los planetas del propio usuario para saber desde dónde puede lanzar misiones
  const userPlanets = await prisma.building.findMany({
    where: { userId: session.user.id },
    select: { id: true, coordX: true, coordY: true, coordZ: true },
  });

  return (
    <div className="container mx-auto p-4">
      <h1 className="text-3xl font-bold mb-4">Galaxia</h1>
      <GalaxyNavigation currentCoords={{ coordX, coordY }} />
      <GalaxyView planets={planetsInView} userPlanets={userPlanets} />
    </div>
  );
}
```

---

### **4. Desglose de Componentes**

#### **4.1. `GalaxyNavigation` (Client Component)**
-   **Ruta:** `components/galaxy/GalaxyNavigation.tsx`
-   **Propósito:** Permite al usuario introducir coordenadas y navegar por el mapa.
-   **Lógica:**
    -   Es un **Client Component** (`"use client"`).
    -   Usa `useState` para manejar los valores de los inputs de coordenadas.
    -   Usa el hook `useRouter` de `next/navigation`. Al hacer clic en "Ir", construye la nueva URL con los parámetros de búsqueda (ej. `/galaxy?coordX=100&coordY=50`) y llama a `router.push()`. Esto desencadena una nueva renderización del Server Component `GalaxyPage` con los datos de la nueva vista.

#### **4.2. `GalaxyView` (Client Component)**
-   **Ruta:** `components/galaxy/GalaxyView.tsx`
-   **Propósito:** El contenedor principal e interactivo del mapa.
-   **Props:** `{ planets: BuildingWithUser[], userPlanets: Building[] }`
-   **Lógica:**
    -   Es un **Client Component** para manejar el estado de la interacción.
    -   Usa `useState` para guardar el planeta seleccionado: `const [selectedPlanet, setSelectedPlanet] = useState(null);`
    -   Usa `useState` para controlar la visibilidad del modal de misión: `const [missionModal, setMissionModal] = useState({ isOpen: false, type: null });`
    -   Renderiza los planetas en una cuadrícula (CSS Grid).
    -   Muestra condicionalmente el panel de detalles (`PlanetDetailPanel`) y el modal (`MissionLaunchModal`).

#### **4.3. `PlanetTile` (Client Component)**
-   **Ruta:** `components/galaxy/PlanetTile.tsx`
-   **Propósito:** Representa un planeta en la cuadrícula.
-   **Props:** `{ planet, onSelect }`
-   **Lógica:** Muestra el nombre del planeta y su propietario. Al hacer clic, llama a la función `onSelect(planet)` pasada desde `GalaxyView`.

#### **4.4. `PlanetDetailPanel` (Client Component)**
-   **Ruta:** `components/galaxy/PlanetDetailPanel.tsx`
-   **Propósito:** Muestra información detallada del planeta seleccionado y las acciones posibles.
-   **Props:** `{ planet, onLaunchMission: (type: string) => void }`
-   **Lógica:** Si `planet` no es nulo, muestra sus detalles. Contiene los botones "Atacar", "Espiar", "Transportar", que al ser pulsados llaman a `onLaunchMission('ATTACK')`, etc., para que `GalaxyView` abra el modal correspondiente.

#### **4.5. `MissionLaunchModal` (Client Component)**
-   **Ruta:** `components/galaxy/MissionLaunchModal.tsx`
-   **Propósito:** Formulario para configurar y lanzar una misión.
-   **Props:** `{ targetPlanet, missionType, userPlanets, onClose }`
-   **Lógica:**
    -   Formulario complejo con estado local (`useState`) para manejar la selección de tropas y recursos.
    -   Un desplegable permite seleccionar el planeta de origen de entre los `userPlanets`.
    -   El botón "Lanzar Misión" invoca la `launchMissionAction`. Utiliza `useTransition` para mostrar un estado de carga.

---

### **5. Server Actions Relevantes**

La acción de lanzar una misión es crítica y debe ser segura y atómica.

#### **Acción: `launchMissionAction`**
-   **Ruta:** `app/actions/missionActions.ts`
-   **Parámetros:** `(payload: MissionPayload)` donde `MissionPayload` es un objeto validado por Zod que contiene `{ originPlanetId, targetPlanetId, missionType, troops, resources }`.
-   **Lógica:**
    1.  Declarar `"use server"`.
    2.  Validar el `payload` con Zod.
    3.  Obtener la sesión del usuario.
    4.  **Iniciar una transacción de base de datos** con `prisma.$transaction`.
    5.  **Dentro de la transacción:**
        a.  Leer los datos del planeta de origen, asegurándose de que pertenece al usuario (`where: { id: originPlanetId, userId: session.user.id }`). Incluir las `tropas`.
        b.  **Validar:** Comprobar si el usuario tiene suficientes tropas para la misión. Si no, `throw new Error("Tropas insuficientes.")`.
        c.  **Calcular:** Calcular la duración del viaje basándose en la distancia entre las coordenadas de origen y destino.
        d.  **Ejecutar mutaciones:**
            -   Restar las tropas del registro `Troop` del planeta de origen.
            -   Crear una nueva entrada en la tabla `Mission` con todos los detalles: coordenadas, tropas, tipo de misión, `userId`, y `finishesAt`.
    6.  Si la transacción tiene éxito, llamar a `revalidatePath('/dashboard')` para que el movimiento de flota aparezca en la visión general.
    7.  Devolver un objeto `{ success: true }` o `{ error: "Mensaje" }` para notificar al cliente.

```typescript
// app/actions/missionActions.ts
"use server";
// ... (imports)

// Definir el esquema de Zod para el payload
const missionPayloadSchema = z.object({ /* ... */ });

export async function launchMissionAction(payload: unknown) {
  const validation = missionPayloadSchema.safeParse(payload);
  if (!validation.success) return { error: "Payload inválido." };

  const { originPlanetId, targetPlanetId, missionType, troops } = validation.data;

  // ... (obtener sesión)

  try {
    await prisma.$transaction(async (tx) => {
      // Lógica de la transacción:
      // 1. Leer tropas del planeta de origen (asegurando propiedad).
      // 2. Validar si hay suficientes tropas.
      // 3. Calcular duración.
      // 4. Restar tropas y crear la misión.
    });
  } catch (error) {
    return { error: error.message };
  }

  revalidatePath('/dashboard');
  return { success: true };
}
```
Este diseño modulariza la compleja interfaz del mapa, manteniendo la lógica de negocio crítica y las validaciones de seguridad en el servidor.
