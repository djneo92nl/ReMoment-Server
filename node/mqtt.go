package main

import (
	"encoding/json"
	"fmt"
	"time"

	paho "github.com/eclipse/paho.mqtt.golang"
)

// mqttLoop is the doorbell and the live status, nothing more. A message on cmd/job only makes the node ask the
// server for its jobs over REST, so a forged message on the open broker can do nothing. The node never acts on
// the payload. If the broker is unreachable the node polls (pollEvery) and loses only latency.
func (n *Node) mqttLoop(id string, done <-chan struct{}) {
	inf, err := n.api.info()
	if err != nil || inf.MQTT.Host == "" {
		n.logf("mqtt: no broker address from /api/info (%v); polling only", err)
		return
	}
	statusTopic := fmt.Sprintf("remoment/node/%s/status", id)
	cmdTopic := fmt.Sprintf("remoment/node/%s/cmd/#", id)
	online, _ := json.Marshal(map[string]any{"state": "online", "version": version})
	offline, _ := json.Marshal(map[string]any{"state": "offline"})

	opts := paho.NewClientOptions().
		AddBroker(fmt.Sprintf("tcp://%s:%d", inf.MQTT.Host, inf.MQTT.Port)).
		SetClientID("remoment-node-"+id).
		SetAutoReconnect(true).SetConnectRetry(true).SetConnectRetryInterval(10*time.Second).
		SetKeepAlive(30*time.Second).
		SetWill(statusTopic, string(offline), 1, true).
		SetOnConnectHandler(func(c paho.Client) {
			c.Publish(statusTopic, 1, true, string(online))
			c.Subscribe(cmdTopic, 1, func(_ paho.Client, _ paho.Message) {
				select {
				case n.nudge <- struct{}{}:
				default:
				}
			})
			n.logf("mqtt: connected to %s:%d", inf.MQTT.Host, inf.MQTT.Port)
		}).
		SetConnectionLostHandler(func(_ paho.Client, err error) { n.logf("mqtt: lost: %v", err) })

	c := paho.NewClient(opts)
	c.Connect() // with ConnectRetry the token completes later; we do not block on it
	<-done
	if c.IsConnected() {
		c.Publish(statusTopic, 1, true, string(offline)).WaitTimeout(2 * time.Second)
	}
	c.Disconnect(250)
}
