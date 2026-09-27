// Container App genérica — la misma imagen sirve web/worker/scheduler; el rol
// lo define command/args y la presencia de ingress.
param location string
param name string
param environmentId string
param identityId string
param registryServer string
param image string
param command array = []
param args array = []
param externalIngress bool
param targetPort int = 8080
param healthPath string = '/healthz'
@description('Readiness (DB + esquema runtime). Liveness sigue en healthPath.')
param readinessPath string = '/readyz'
@allowed(['0.25', '0.5', '0.75', '1', '1.25', '1.5', '1.75', '2'])
param cpu string
@allowed(['0.5Gi', '1Gi', '1.5Gi', '2Gi', '2.5Gi', '3Gi', '3.5Gi', '4Gi'])
param memory string
param minReplicas int
param maxReplicas int
@description('Variables no sensibles nombre→valor.')
param env object = {}
@secure()
@description('Secretos nombre→valor. Se exponen como env con el mismo nombre vía secretRef.')
param secrets object = {}

// Container Apps exige nombres de secreto en minúsculas/guiones.
var secretItems = [for s in items(secrets): {
  name: toLower(replace(s.key, '_', '-'))
  value: s.value
}]

var plainEnvVars = [for e in items(env): { name: e.key, value: string(e.value) }]
var secretEnvVars = [for s in items(secrets): { name: s.key, secretRef: toLower(replace(s.key, '_', '-')) }]
var envVars = concat(plainEnvVars, secretEnvVars)

var probes = externalIngress ? [
  {
    type: 'Startup'
    httpGet: { path: healthPath, port: targetPort }
    initialDelaySeconds: 5
    periodSeconds: 5
    failureThreshold: 12
  }
  {
    type: 'Readiness'
    httpGet: { path: readinessPath, port: targetPort }
    periodSeconds: 10
  }
  {
    type: 'Liveness'
    httpGet: { path: healthPath, port: targetPort }
    periodSeconds: 30
    failureThreshold: 3
  }
] : []

resource app 'Microsoft.App/containerApps@2024-03-01' = {
  name: name
  location: location
  identity: {
    type: 'UserAssigned'
    userAssignedIdentities: { '${identityId}': {} }
  }
  properties: {
    managedEnvironmentId: environmentId
    workloadProfileName: 'Consumption'
    configuration: {
      activeRevisionsMode: 'Single'
      ingress: externalIngress ? {
        external: true
        targetPort: targetPort
        transport: 'http'
        allowInsecure: false
      } : null
      registries: [
        {
          server: registryServer
          identity: identityId
        }
      ]
      secrets: secretItems
    }
    template: {
      containers: [
        {
          name: name
          image: image
          command: empty(command) ? null : command
          args: empty(args) ? null : args
          resources: {
            cpu: json(cpu)
            memory: memory
          }
          env: envVars
          probes: probes
        }
      ]
      scale: {
        minReplicas: minReplicas
        maxReplicas: maxReplicas
      }
    }
  }
}

output id string = app.id
output fqdn string = externalIngress ? app.properties.configuration.ingress.fqdn : ''
